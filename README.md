# melytics

Privacy-first, cookieless web analytics. GoatCounter-inspired, with a modern
dashboard, ad-blocker-resistant first-party ingestion, and a two-tier privacy
model (consentless by default; consent-gated extras only where the law requires).

## Layout

| Dir | What |
|---|---|
| `api/` | Laravel API — ingest, enrichment, rollups, stats endpoints |
| `dashboard/` | Vue 3 + Tailwind SPA — mounts anywhere (relative base + hash router) |
| `tracker/` | The snippet. <1KB gzipped, zero deps |
| `deploy/` | Hostinger shared-hosting guide, first-party proxy template, docker-compose for VPS |

## Quick start (local)

```bash
cd api && composer install && cp .env.example .env && php artisan key:generate
php artisan migrate && php artisan melytics:user you@example.com
php artisan serve --port=8901
# separate shell
cd dashboard && npm install && npm run dev   # proxies /api → :8901
```

Build the tracker: `cd tracker && npx esbuild src/m.js --minify --format=iife --outfile=dist/m.js`

## Install on a site

```html
<script defer src="/js/app-m.js" data-site="YOUR_SITE_KEY"></script>
<noscript><img src="/api/echo.gif?k=YOUR_SITE_KEY" alt=""></noscript>
```

Serve both paths first-party via the proxy rules in
`deploy/htaccess-proxy-template.txt` — that's what makes capture
ad-blocker-resistant.

## Privacy model

- **Tier 1 (always, consentless):** no cookies, no fingerprinting, no PII.
  Uniques via a daily-rotating salted hash; IP used in-memory only.
- **Tier 2 (opt-in, consent-gated):** retention/journeys via localStorage id.
  Consent is requested only in jurisdictions that require it (geo-detected at
  ingest); elsewhere it activates automatically. `melytics.consent(true)` hooks
  any CMP. (Tier 2 features land in phase 3.)

Custom events: `melytics.track('signup', {plan: 'pro'})`.
