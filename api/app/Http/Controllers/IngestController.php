<?php

namespace App\Http\Controllers;

use App\Models\Hit;
use App\Models\Site;
use App\Services\Enrichment;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

class IngestController extends Controller
{
    public function __invoke(Request $request, Enrichment $enrich): Response
    {
        $data = $request->validate([
            'k' => 'required|string|max:24',
            'u' => 'required|string|max:2048',
            'r' => 'nullable|string|max:2048',
            'w' => 'nullable|integer|min:0|max:20000',
            'z' => 'nullable|string|max:64',
            'e' => 'nullable|string|max:64',
            'p' => 'nullable|array|max:50',
            'i' => 'nullable|alpha_num|max:32',
        ]);

        // The site key is public (it ships in the tracker <script>), so a beacon
        // is only as trustworthy as its payload. Bound the custom-event props:
        // every other field is length-capped, but a raw array could be a
        // megabytes-deep blob that bloats event_props and slows JSON queries.
        $props = $data['p'] ?? null;
        if ($props !== null && strlen(json_encode($props)) > 4096) {
            $props = null; // keep the pageview/event; drop the oversized payload
        }

        [$siteId, $tier2, $domain] = Cache::remember(
            'site3:'.$data['k'], // key bumped: value shape now [id, tier2, domain]
            300,
            function () use ($data) {
                $site = Site::where('key', $data['k'])->first(['id', 'tier2_enabled', 'domain']);

                return $site ? [$site->id, $site->tier2_enabled, $site->domain] : [0, false, null];
            }
        );
        if (! $siteId) {
            return response()->noContent(); // never leak which keys exist
        }

        $ua = $request->userAgent();
        $url = parse_url($data['u']);
        $event = $data['e'] ?? null;
        if ($enrich->isBot($ua)) {
            $this->logBot($siteId, $enrich->botName($ua), $url['path'] ?? '/', $event);

            return response()->noContent();
        }

        // Beacon host must match the site's registered domain (or a subdomain).
        // Blocks trivial cross-site poisoning with a scraped key. Mismatches are
        // parked in bot_hits ('Foreign domain') rather than dropped, so a real
        // domain misconfig is visible on the Bots card instead of silent.
        if (! $this->hostAllowed($url['host'] ?? null, $domain)) {
            $this->logBot($siteId, 'Foreign domain', $url['path'] ?? '/', $event);

            return response()->noContent();
        }

        // Asset/feed URLs are not pages: crawlers and platform pollers (Shopify
        // apps fetching /products.json every 2 min, seen live 2026-08-28) run
        // the page's JS context or replay beacon URLs and pollute stats. Drop
        // pageviews and their pings for them; custom events keep their payload.
        if (($event === null || $event === '__ping')
            && preg_match('/\.(?:js|mjs|css|json|xml|rss|atom|txt|ico|png|jpe?g|gif|svg|webp|avif|woff2?|ttf|otf|eot|map|webmanifest|pdf|zip)$/i', $url['path'] ?? '/')) {
            $this->logBot($siteId, 'Asset scraper', $url['path'] ?? '/', $event);

            return response()->noContent();
        }
        parse_str($url['query'] ?? '', $query);
        $refHost = null;
        if (! empty($data['r'])) {
            $refHost = parse_url($data['r'], PHP_URL_HOST);
            if ($refHost === ($url['host'] ?? null)) {
                $refHost = null; // internal navigation is not a referral
            }
        }

        $parsed = $enrich->parseUserAgent($ua);

        Hit::create([
            'site_id' => $siteId,
            'visitor_hash' => $enrich->visitorHash($siteId, $request),
            'path' => substr($url['path'] ?? '/', 0, 512),
            'referrer_host' => $refHost ? substr($refHost, 0, 255) : null,
            'utm_source' => isset($query['utm_source']) ? substr($query['utm_source'], 0, 255) : null,
            'utm_medium' => isset($query['utm_medium']) ? substr($query['utm_medium'], 0, 255) : null,
            'utm_campaign' => isset($query['utm_campaign']) ? substr($query['utm_campaign'], 0, 255) : null,
            'country' => $enrich->country($request, $data['z'] ?? null),
            'device' => $parsed['device'],
            'browser' => $parsed['browser'],
            'os' => $parsed['os'],
            'screen_w' => $data['w'] ?? null,
            'event' => $data['e'] ?? null,
            'event_props' => $props,
            // key omitted entirely unless consented, so inserts still work pre-migration
        ] + ($tier2 && ! empty($data['i']) ? ['visitor_id' => $data['i']] : []));

        return response()->noContent();
    }

    /**
     * Count what gets blocked — bots never enter hits, but the dashboard Bots
     * card shows them. Pageviews only: a bot's pings/events would count one
     * visit many times over. Ingest must never fail on this (pre-migration
     * installs after a code-only deploy), so table errors are swallowed.
     */
    private function logBot(int $siteId, string $name, string $path, ?string $event): void
    {
        if ($event !== null) {
            return;
        }
        try {
            \App\Models\BotHit::create(['site_id' => $siteId, 'name' => substr($name, 0, 64), 'path' => substr($path, 0, 512)]);
        } catch (\Throwable) {
        }
    }

    /**
     * Does a beacon's URL host belong to this site? Matches the registered
     * domain or any subdomain of it, ignoring a leading "www." on either side.
     * Permissive when the host or domain is unknown (older sites have no domain,
     * pixel fallbacks carry no host) — this raises the cost of poisoning, it is
     * not an auth boundary a determined spoofer can't forge a Host past.
     */
    private function hostAllowed(?string $host, ?string $domain): bool
    {
        if (! $host || ! $domain) {
            return true;
        }
        $strip = fn (string $h) => preg_replace('/^www\./i', '', strtolower($h));
        $host = $strip($host);
        $domain = $strip($domain);

        return $host === $domain || str_ends_with($host, '.'.$domain);
    }

    /** <noscript> 1px gif fallback: GET /api/echo.gif?k=...&u=... */
    public function pixel(Request $request, Enrichment $enrich): Response
    {
        $request->merge(['u' => $request->query('u', $request->header('Referer', '/'))]);
        try {
            $this->__invoke($request, $enrich);
        } catch (\Throwable) {
            // a broken pixel must never error the page
        }

        return response(base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7'), 200)
            ->header('Content-Type', 'image/gif')
            ->header('Cache-Control', 'no-store');
    }
}
