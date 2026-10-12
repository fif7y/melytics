# Deploying melytics to shared / cPanel-style hosting

Works on any PHP shared or cloud host (cPanel, hPanel, Plesk, …) with PHP 8.2+,
a database (MySQL or SQLite), and cron. Examples below use `stats.example.com`.

## Easiest: release zip + web installer (no terminal)

1. Download [`melytics.zip`](https://github.com/fif7y/melytics/releases/latest/download/melytics.zip)
   from the latest release. It bundles the API with production dependencies, the dashboard at
   `public/app/`, and the tracker at `public/m.js` — one docroot serves all three.
2. Upload + extract via your panel's file manager. The files sit at the top
   of the zip (no folder inside a folder), and a bundled root `.htaccess`
   routes everything through `public/` and keeps the app internals private.
   - File manager can extract **into the current folder**? Upload the zip
     into the folder your (sub)domain serves and extract it there.
   - File manager always asks for a **new folder name** (Hostinger et al.)?
     Upload the zip one level up, remove the (sub)domain's folder if the
     panel already made one, and extract using that folder's exact name —
     the extraction creates the docroot for you.

   Delete the zip afterward. Files one folder too deep? Move that folder's
   contents up one level. And if your panel lets you point the document root
   at `…/public` directly, do — it's tidier.
3. Visit the domain → the installer at `/install` first asks for a one-time
   setup code: open `storage/install-token.txt` in your file manager and
   paste it (proves it's you, not a stranger who found the URL first). It
   then checks requirements, creates your login and first site on SQLite,
   and walks you through the rest on screen: the tracking snippet, the cron
   job, and signing in.

   For the cron: your panel's Cron Jobs form, command
   `/bin/sh /path/to/melytics/cron.sh` (the installer shows it with your real
   path). Pick **Custom** (not PHP) if the form asks, and set every schedule
   field — minute, hour, day, month, weekday — to its "Every…" / `*` option:
   that means run once a minute. Use the bundled `cron.sh` rather than an
   inline `cd … && php artisan …` command — some hosts' cron (Hostinger
   among them) silently never runs inline commands, but always runs a script.

   No cron yet? Stats still work — they refresh whenever the dashboard is
   opened — but the cron makes them continuous and powers digest/alert emails.
   The dashboard reminds you (with the exact command) until it's running.

   Emails (digests, alerts, password resets) go out via the server's
   sendmail by default. Deliverability shaky? An admin can connect any
   email provider over SMTP in the dashboard — **Settings → Email
   delivery** — no file editing.

Even shorter: upload just
[`melytics-installer.php`](https://github.com/fif7y/melytics/releases/latest/download/melytics-installer.php)
(attached to every release) into the folder your
domain serves and open it in the browser — it downloads the latest release,
unpacks it there, deletes itself, and drops you on the setup screen.

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
`storage/geoip/GeoLite2-Country.mmdb` (inside the melytics folder) and run
`composer require geoip2/geoip2` there.
Without it, country comes from CDN headers (Cloudflare etc.) or stays empty.
