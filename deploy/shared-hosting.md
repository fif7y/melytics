# Deploying melytics to shared / cPanel-style hosting

Works on any PHP shared or cloud host (cPanel, hPanel, Plesk, …) with PHP 8.2+,
a database (MySQL or SQLite), and cron. Examples below use `stats.example.com`.

## Easiest: release zip + web installer (no terminal)

1. Build or download `melytics-<version>.zip` (`bash deploy/build-release.sh`).
   It bundles the API with production dependencies, the dashboard at
   `public/app/`, and the tracker at `public/m.js` — one docroot serves all three.
2. Upload + extract via your panel's file manager. The zip has no wrapper
   folder, and a bundled root `.htaccess` routes everything through `public/`
   and blocks access to the app internals. If the file manager extracts in
   place, upload the zip into the folder your (sub)domain serves and extract
   there. If it only extracts into a new named folder (Hostinger et al.),
   upload the zip one level up, delete the (sub)domain's folder if it already
   exists, and extract using that folder's exact name — the extraction
   creates the docroot itself. Delete the zip afterward; if files ended up
   one folder too deep, move that folder's contents up. If you *can* point the document root at `…/melytics/public`
   instead, do — it's tidier.
3. Visit the domain → the installer at `/install` checks requirements, creates
   your login and first site on SQLite, and shows the tracking snippet plus the
   cron line to add in your panel (every minute):
   `cd ~/melytics && php artisan schedule:run >> /dev/null 2>&1`

   No cron yet? Stats still work — they refresh whenever the dashboard is
   opened — but the cron makes them continuous and powers digest/alert emails.
   The dashboard reminds you (with the exact line) until it's running.

Even shorter: upload just `deploy/melytics-installer.php` into the folder your
domain serves and open it in the browser — it downloads the latest release,
unpacks it there, deletes itself, and drops you on the setup screen.

The rest of this guide is the manual path — use it for MySQL, git deploys, or
custom layouts.

## API (Laravel)

1. Upload `api/` (or git-deploy the repo) outside the public docroot, e.g. `~/melytics/api`.
2. Point a subdomain (`stats.example.com`) docroot at `~/melytics/api/public`
   (your panel → Domains → subdomain → document root). If the docroot can't
   leave the web root, symlink `public_html/stats → ~/melytics/api/public`.
3. Create a database in your panel (or use SQLite); fill `api/.env`:
   `APP_ENV=production`, `APP_KEY` (run `php artisan key:generate`), `DB_*`,
   `CACHE_STORE=database`, `QUEUE_CONNECTION=database`.
4. `php artisan migrate --force`
5. Create your login: `php artisan melytics:user you@example.com`
6. Cron (your panel's Cron Jobs), every minute:
   `cd ~/melytics/api && php artisan schedule:run >> /dev/null 2>&1`
   The scheduler runs rollups every 5 min and pruning nightly.

## Dashboard (static)

1. `cd dashboard && npm run build`
2. Upload `dashboard/dist/*` to the docroot serving the dashboard — either the
   same subdomain under `/app` or anywhere else; the build is relative-path
   (`base: './'`, hash router) so it mounts at any path, e.g. `example.com/stats`.
3. If API and dashboard are on different origins, add before the bundle tag in
   `index.html`: `<script>window.MELYTICS_API='https://stats.example.com/api'</script>`

## Tracker on each measured site

See `htaccess-proxy-template.txt` — proxy `/js/app-m.js` and `/api/echo*`
first-party, then drop the two-line snippet before `</body>`.

## Optional: "Continue with Google"

Easiest: in the dashboard, **Account → Sign-in → set up** walks you through it —
it links to Google's console, gives you the redirect URI to paste, and stores
the two values Google hands back. No file editing.

Manually instead: create an OAuth 2.0 Client ID (type: Web application) at
[console.cloud.google.com](https://console.cloud.google.com) with authorized
redirect URI `https://stats.example.com/api/auth/google/callback`, then add
`GOOGLE_CLIENT_ID` and `GOOGLE_CLIENT_SECRET` to `.env`.

Existing accounts match by email; new accounts via Google are only created when
`MELYTICS_REGISTRATION=true`.

## Optional: country geolocation

Download MaxMind's free GeoLite2-Country.mmdb into
`api/storage/geoip/GeoLite2-Country.mmdb` and `composer require geoip2/geoip2`.
Without it, country comes from CDN headers (Cloudflare etc.) or stays empty.
