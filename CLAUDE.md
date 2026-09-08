# melytics — agent notes

Privacy-first, cookieless web analytics: `tracker/` (snippet) → `api/` (Laravel, SQLite by default) → `dashboard/` (Vue 3 + Tailwind SPA). `mcp/` is a stdio MCP server over the same stats. Public repo, AGPL-3.0 — keep hostnames, keys and personal data out of committed files (private ops notes live in Claude's project memory and the second-brain vault, not here).

## Commands

| What | Command |
|---|---|
| API dev server | `cd api && php artisan serve --port=8901` (vite proxies `/api` here) |
| Dashboard dev | `npm run dev --prefix dashboard` (or the `melytics-dashboard` preview in `.claude/launch.json`) |
| Dashboard build + typecheck | `npm run build --prefix dashboard` (`vue-tsc -b && vite build`) |
| Tracker build | `cd tracker && npx esbuild src/m.js --minify --format=iife --outfile=dist/m.js` |
| Rollups | `php artisan melytics:rollup --hours=N` (rollups are keyed in site-local time) |
| Demo data | `php artisan db:seed --class=DemoSeeder` then rollup `--hours=2208` |
| Release | `git tag -a vX.Y.Z -m "notes" && git push --tags` — CI (`.github/workflows/release.yml`) builds and publishes; tag message = release notes |

## Architecture in one breath

- **Ingest:** `IngestController` → `Enrichment` (UA, geo, IP hash) → `hits`. Beacon host must match the site domain or the hit is diverted to `bot_hits`.
- **Stats:** `Stats` / `Tier2Stats` / `ChartStats` read rollups (`rollup_daily`, `rollup_hourly`) for the fast paths and raw `hits` for cross-filters, event props and goal revenue. `SqlDialect` hides SQLite vs MySQL differences.
- **Dashboard state:** per-site prefs use `useSiteScopedRef` (`lib/persist.ts`), keys `melytics_*:<siteId>`. Cards are `data-drag-key` panels in `views/Dashboard.vue`; each card gets a layout via `LayoutMenu` and a 1–3 column span.
- **Self-update:** `App\Support\Version` reads `VERSION` from the release zip; `UpdateController` pulls the next GitHub release and verifies `melytics.zip.sha256` when present.
- **Install:** release zips serve from `public/`; new installs need the setup code in `storage/install-token.txt`.

## Conventions

- Surgical diffs, match existing style, no drive-by refactors.
- Dashboard: Tailwind utilities + CSS variables (`--accent`, `--ink`, `--bg`…). Charts are hand-written SVG in `components/charts/`; they take a `span` prop and widen the viewBox instead of stretching.
- Migrations must be idempotent (`Schema::hasColumn` / `hasIndex` guards) — SQLite multi-statement migrations can partially fail.
- Never seed or send test hits to a production site. Local E2E: create a throwaway user + site, seed `hits` via tinker or `DB::table`, roll up, and delete everything after (including `api/storage/framework/rollup-*.heartbeat`, which lazy rollups drop).
- `docs/SESSION_LOG.md` is gitignored and holds session handoffs — read its tail when resuming; write to it via the `handoff` skill.
- Release zips in the repo root are gitignored build residue; leave them alone.
