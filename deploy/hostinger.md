# Deploying melytics to Hostinger shared/cloud hosting

## API (Laravel)

1. Upload `api/` (or git-deploy the repo) outside `public_html`, e.g. `~/melytics/api`.
2. Point the subdomain `stats.fif7y.com` docroot at `~/melytics/api/public`
   (hPanel → Domains → subdomain → document root). If docroot can't leave
   `public_html`, symlink `public_html/stats → ~/melytics/api/public`.
3. Create a MySQL database in hPanel; fill `api/.env`:
   `APP_ENV=production`, `APP_KEY` (run `php artisan key:generate`), `DB_*`,
   `CACHE_STORE=database`, `QUEUE_CONNECTION=database`.
4. `php artisan migrate --force`
5. Create your login: `php artisan melytics:user you@example.com`
6. Cron (hPanel → Advanced → Cron Jobs), every minute:
   `cd ~/melytics/api && php artisan schedule:run >> /dev/null 2>&1`
   The scheduler runs rollups every 5 min and pruning nightly.

## Dashboard (static)

1. `cd dashboard && npm run build`
2. Upload `dashboard/dist/*` to the docroot serving the dashboard — either the
   same subdomain under `/app` or anywhere else; the build is relative-path
   (`base: './'`, hash router) so it mounts at any path, e.g. `fif7y.com/melytics`.
3. If API and dashboard are on different origins, add before the bundle tag in
   `index.html`: `<script>window.MELYTICS_API='https://stats.fif7y.com/api'</script>`

## Tracker on each measured site

See `htaccess-proxy-template.txt` — proxy `/js/app-m.js` and `/api/echo*`
first-party, then drop the two-line snippet before `</body>`.

## Optional: country geolocation

Download MaxMind's free GeoLite2-Country.mmdb into
`api/storage/geoip/GeoLite2-Country.mmdb` and `composer require geoip2/geoip2`.
Without it, country comes from CDN headers (Cloudflare etc.) or stays empty.
