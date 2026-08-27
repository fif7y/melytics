#!/usr/bin/env bash
# Build a self-contained release zip: upload → point docroot at public/ →
# visit /install. API + dashboard (public/app/) + tracker (public/m.js) in one tree.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
VERSION="${1:-$(date +%Y.%m.%d)}"
TMP="$(mktemp -d)"
STAGE="$TMP/melytics"
trap 'rm -rf "$TMP"' EXIT

echo "→ staging api/"
rsync -a "$ROOT/api/" "$STAGE/" \
  --exclude vendor --exclude node_modules --exclude tests --exclude phpunit.xml \
  --exclude '.env' --exclude '.env.example' --exclude 'database/*.sqlite*' \
  --exclude 'storage/logs/*' --exclude 'storage/framework/cache/data/*' \
  --exclude 'storage/framework/sessions/*' --exclude 'storage/framework/views/*' \
  --exclude 'bootstrap/cache/*.php' \
  --exclude CLAUDE.md --exclude AGENTS.md --exclude README.md

echo "→ composer install (prod)"
composer install --working-dir="$STAGE" --no-dev --optimize-autoloader --no-interaction --quiet

echo "→ dashboard build"
(cd "$ROOT/dashboard" && npm ci --silent && npm run build --silent)
mkdir -p "$STAGE/public/app"
rsync -a "$ROOT/dashboard/dist/" "$STAGE/public/app/"

echo "→ tracker build"
(cd "$ROOT/tracker" && npx esbuild src/m.js --minify --format=iife --outfile="$STAGE/public/m.js" --log-level=warning)

echo "→ release .env + root .htaccess + VERSION"
cp "$ROOT/deploy/release-env-template" "$STAGE/.env"
cp "$ROOT/deploy/release-root-htaccess" "$STAGE/.htaccess"
# Read back by App\Support\Version — update check + one-click updater key off it.
printf '%s\n' "$VERSION" > "$STAGE/VERSION"

OUT="$ROOT/melytics-$VERSION.zip"
rm -f "$OUT" "$ROOT/melytics.zip"
# Flat zip (no top-level folder): panel file managers extract it straight into
# the (sub)domain folder without nesting. melytics-installer.php and the
# self-updater both tolerate flat and legacy melytics/-prefixed layouts.
(cd "$STAGE" && zip -qr "$OUT" .)
cp "$OUT" "$ROOT/melytics.zip"   # stable name — deploy/melytics-installer.php fetches releases/latest/download/melytics.zip
echo "Built $OUT ($(du -h "$OUT" | cut -f1)) + melytics.zip (attach BOTH to the GitHub release)"
