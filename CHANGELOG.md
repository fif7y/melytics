# Changelog

Release notes for versions up to 0.5.6. Newer releases are on the [Releases page](https://github.com/fif7y/melytics/releases).

## v0.5.6 (2026-10-12)

The site switcher now shows each site's connection status (a coloured dot plus last-visit time), so you can see which sites are receiving visits without opening Settings. Settings → Sites moved to the top. Fixes a status reset after changing a site's currency.

## v0.5.5 (2026-10-12)

Settings → Sites now lists every tracked site with a connection status (recent visit, stale, not connected, wrong domain) and drag-to-reorder. The site switcher follows your order, and the top site opens first. Includes a database migration (runs automatically on self-update).

## v0.5.4 (2026-09-09)

Hide the card Width control on phones: below the sm breakpoint the grid is a single column, so width had no effect.

## v0.5.3 (2026-09-08)

Update banner now appears within an hour of a release: 1h release-check cache plus an hourly scheduled refresh; failed GitHub lookups are cached 10 min instead of stalling each request.

## v0.5.2 (2026-09-08)

Account drawer: 'Check for updates' next to the version number (admin, release installs) forces a fresh release lookup and shows the update banner immediately.

## v0.5.1 (2026-09-08)

Fix: strip-layout legend let very long labels (e.g. iOS file paths) spill outside the card.

## v0.5.0 (2026-09-07)

v0.5.0 — chart layouts + resizable cards

- Every breakdown card gets a layout menu: List, Donut, Strip, Treemap (pages), Compare (this vs previous period), Trend (sparkline per row)
- New cards: Hours (punchcard / clock), Mix over time (stacked share / rank, any dimension), Visit duration, Paths (source → landing → outcome)
- Calendar chart style, Distribution layout for Web Vitals, Pulse layout for Live
- Resizable cards: drag a card's right edge or pick a width in its menu to span 1–3 columns; wide lists show 16 rows in two columns, charts widen instead of stretching
- One accent in five tints across every chart; grey for Other / previous period
- API: hours / mix / trend / durations / paths, vitals histograms, live recent — no migration needed

## v0.4.5 (2026-09-02)

Mobile fix: custom-range date pickers no longer spill past the modal on iOS Safari.

## v0.4.4 (2026-08-29)

Mobile fixes: drag-to-reorder now works on touch devices (long-press lifts a drag ghost that follows your finger — stat tiles, dashboard grid, goals/funnels row, and the modules panel), and the page no longer pans sideways on phones (card grids pinned to the viewport, long paths truncate again).

## v0.4.3 (2026-08-29)

v0.4.3

- MCP (stdio): get_event_props tool — event property breakdown/aggregation (event, prop, optional by grouping), matching the dashboard's Event properties card
- Docs: README + install guides caught up to v0.4.2 (event props, goal revenue, bots, Email delivery, v0.4.0 setup code)

## v0.4.2 (2026-08-28)

Per-site currency & in-app email delivery.

Sites can name a revenue currency — goal revenue then renders as real money (€49.99, not 49.99), plain numbers stay the default. And the ESP swap: Settings → Email delivery lets the admin point melytics at any SMTP provider (Resend, Postmark, Brevo…) with a guided form and a send-a-test-email button, because shared-host sendmail too often lands digests, alerts and password resets in spam. Reverting goes back to host sendmail, never to silent logging.

## v0.4.1 (2026-08-28)

Event properties & goal revenue.

Explore your custom-event properties from the dashboard: string properties get a value distribution, numeric ones get sum/avg/count, and a numeric property grouped by a string one gives revenue-by-segment (by plan, by currency, ...). Event goals can now name a numeric property to sum — each goal reports total revenue and average per converting visitor (guarded goals.value_prop migration, applied on update). Also: CSP is injected at build time only (fixes vite dev HMR) and a sharper README tagline.

## v0.4.0 (2026-08-28)

Security hardening pass 2.

Install setup-token gate (only someone with file-system access can claim the admin account on a fresh public instance), Content-Security-Policy on the dashboard, MCP connector token expiry, OAuth state bound to the browser, visitor-id HMAC, and a zip-slip guard on the self-updater. Completes the security audit (builds on v0.3.9's ingest/headers/checksum hardening).

## v0.3.9 (2026-08-28)

Ingest throttle + domain-bind + event_props cap, trusted-IP header, update checksum verification, scoped MCP tokens, security headers, and auth/XSS hardening.

## v0.3.8 (2026-08-28)

The update banner now surfaces on open dashboards within the 60s auto-refresh — no page reload needed. The (12h-cached) release check rides the batched `/dashboard` payload.

## v0.3.7 (2026-08-28)

**Bot traffic vs human.** Blocked bot beacons (self-declared crawlers + asset-path scrapers) are now counted in a new `bot_hits` table instead of dropped silently. New **Bots** dashboard card: share-of-traffic split, blocked vs human counts, top crawler names. Human stats queries and rollups untouched; bot rows follow each site's retention window.

Also: dashboard footer.

Run migrations after updating (the built-in updater does this automatically).

## v0.3.6 (2026-08-28)

## What's new

- **The dashboard now updates itself** — data quietly refreshes every 60 seconds while the tab is visible, updating numbers and charts in place with no spinner and no page reload. 60s matches how often rollups land, so nothing faster could show newer data anyway. Hidden tabs pause entirely and catch up the moment you come back.

## Upgrading

One-click from the dashboard update banner. No migrations.

## v0.3.5 (2026-08-28)

## Performance

- **One request per dashboard load** — a site switch or range change used to fire ~16 API calls, each booting the framework (the dominant cost on shared hosting). The new `/sites/{id}/dashboard` endpoint returns everything in a single payload.
- **Faster session queries** — entry/exit pages now resolve in the same aggregation pass instead of two correlated subqueries per session (verified byte-identical output).
- **Cleaner data** — asset and feed URLs (`.json`, `.js`, `.css`, images, fonts…) are no longer recorded as pageviews; platform pollers and crawlers that replay beacon URLs stop polluting stats.

## Fixes

- **Live card now resets on site switch** — it kept the previous site's live visitors until the next 15s poll; it now clears and refetches immediately.
- Duplicate dashboard fetch on first page load removed.

## Upgrading

One-click from the dashboard update banner. No migrations. Dashboard and API must be on the same version (the SPA now uses the batched endpoint).

## v0.3.4 (2026-08-28)

## Fixes

- **Rollup crash under same-second traffic** — two hits landing in the same second could make the hourly/daily rollup abort with a UNIQUE violation, stalling stats freshness and slowing every dashboard load (the lazy self-heal re-ran the failing rollup on each request). Entry/exit page resolution is now deterministic, duplicate groups merge before insert, and one bad site no longer stalls the heartbeat or other sites' rollups.
- **Dashboard polling hygiene** — the 15s live poll no longer stacks requests behind a slow response and pauses while the tab is hidden; switching sites quickly can no longer show a previous site's data.

## Upgrading

One-click from the dashboard update banner. No migrations.

## v0.3.3 (2026-08-28)

## What's new

- **Settings drawer** — a new sliders-icon panel for instance setup: AI assistants (MCP connector), Google sign-in, tracking snippet, and site management.
- **Account, refocused** — the person icon now holds just *you*: appearance, density (moved here from Modules), highlight color, notifications, and sign out.
- **Roomier panels** — Account and Settings are now as wide as Modules on desktop, with more breathing room between sections.
- **Simpler sharing** — the share popover drops the enable checkbox; opening it activates the public link and shows the URL + optional password directly.
- Page title is now "melytics | Dashboard".

## Upgrading

One-click from the dashboard update banner, or replace your install with this zip (keep your `.env` and `database/`). Dashboard-only release — no migrations.

## v0.3.2 (2026-08-28)

## Sticky header

- The dashboard header (site switcher, date range, panel buttons) now **sticks to the top on scroll**, with a frosted-glass background — your controls stay within reach on long dashboards.

Upgrade: one click from the update banner, or replace files with this zip and visit the dashboard.

## v0.3.1 (2026-08-28)

## Per-site layouts & custom highlight colors

Everything about how your dashboard looks is now remembered **per site** — with multiple sites, each keeps its own setup:

- **Per-site layout** — module on/off, card order, stat-tile order, chart style, and density are stored per site (your existing setup carries over to each site until you customize it).
- **Highlight colors** — two new accents (green, orange) plus a **custom color picker**: pick any color, live-previewed as you drag. Per site too.
- **Modules panel redesign** — the settings drawer is now a mini-map of your dashboard: cards match the view's layout and breakpoints, tap to toggle, drag to reorder (Goals/Funnels swappable too), with off modules sinking to the end — all animated.
- **Account panel** — clearer sections (Appearance, Tracking snippet, Sites).
- Fix: Tier-2 tracking toggle now surfaces save errors instead of failing silently.

Upgrade: one click from the update banner, or replace files with this zip and visit the dashboard.

## v0.3.0 (2026-08-27)

Every install is now an MCP server — connect Claude (or any MCP client) straight from the dashboard: Account → AI assistants → generate a connector URL, paste it into Claude's Settings → Connectors. No terminal, no npm. See mcp/README.md.

## v0.2.3 (2026-08-27)

Fixes today's hourly chart cutting off the most recent hours (timezone comparison bug) — the tail of the day rendered flat at 0 for non-UTC sites.

## v0.2.2 (2026-08-27)

## What's new

- **Update confirmation** — after a one-click update finishes, the dashboard now shows a brief "Update successful — you're on vX.Y.Z" banner that fades out on its own, so you know it worked.

## v0.2.1 (2026-08-27)

## What's new

- **Cron made foolproof** — releases now bundle `cron.sh`; the installer and dashboard hand out a `/bin/sh …/cron.sh` command that runs reliably on hosts whose cron ignores inline commands (Hostinger et al.). Cron step now spells out the Custom option and the every-minute schedule.
- **Flat zip** — extracts directly into your (sub)domain folder, no nested folder to untangle.
- **Fixed:** the bundled root `.htaccess` no longer blocks the dashboard at `/app/`.
- **Install guide rewritten** for clarity (README, installer screen, deploy guide) — including a heads-up to purge your site's cache after adding the snippet.
- Site domains are normalized to a bare hostname on save (pasting a full URL is fine).
- The cron reminder banner explains first-run delay and can be minimized (never lost).

*(If you're on 0.2.0: this is also the first update you can install with one click from the dashboard banner.)*

## v0.2.0 (2026-08-27)

## What's new

- **One-click self-update** — when a new release is out, the dashboard shows a banner and the admin account can update the instance in place: the release downloads, swaps in, and migrates automatically. Config (`.env`), your data, and `.htaccess` are never touched.
- **Update notifications** — release installs check GitHub for new versions (cached, no tracking) and surface them in the dashboard banner and the Account panel version line.

## Installing

Upload `melytics.zip` (or the one-file `melytics-installer.php` from the repo) to your host and visit the domain — the web installer does the rest. Details in the [README](https://github.com/fif7y/melytics#install-without-a-terminal-shared-hosting).

**Note for v0.1.0 installs:** v0.1.0 predates the updater, so it won't show an update banner — update once manually by re-uploading this release over your install (keep your `.env`, `database/`, and `storage/`). From v0.2.0 on, updates are one click.

## v0.1.0 (2026-08-27)

First packaged release — install without a terminal.

**Shared hosting (no terminal):**
1. Download `melytics-0.1.0.zip`, upload + extract it into the folder your (sub)domain serves (e.g. `public_html`) with your hosting panel's file manager. A bundled `.htaccess` handles routing — no docroot changes needed.
2. Visit your domain → the web installer checks the server, creates your login and first site (SQLite, no database setup), and hands you the tracking snippet + the one cron line to paste into your panel.

Even shorter: upload just [`deploy/melytics-installer.php`](https://github.com/fif7y/melytics/blob/main/deploy/melytics-installer.php) and open it — it downloads and unpacks this release for you.

`melytics.zip` is the same file under a stable name (used by the one-file installer). Docs: [`deploy/shared-hosting.md`](https://github.com/fif7y/melytics/blob/main/deploy/shared-hosting.md)

**Highlights:** cookieless two-tier privacy model · 667-byte tracker · sessions, channels, goals, funnels, vitals · spike/drop alert + digest emails · optional Google sign-in (guided in-app setup) · MCP server for AI access · runs on $3/mo shared hosting

🤖 Generated with [Claude Code](https://claude.com/claude-code)
