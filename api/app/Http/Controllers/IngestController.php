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
            'p' => 'nullable|array',
            'i' => 'nullable|alpha_num|max:32',
        ]);

        [$siteId, $tier2] = Cache::remember(
            'site2:'.$data['k'], // key bumped: value shape changed from scalar id to [id, tier2]
            300,
            function () use ($data) {
                $site = Site::where('key', $data['k'])->first(['id', 'tier2_enabled']);

                return $site ? [$site->id, $site->tier2_enabled] : [0, false];
            }
        );
        if (! $siteId) {
            return response()->noContent(); // never leak which keys exist
        }

        $ua = $request->userAgent();
        if ($enrich->isBot($ua)) {
            return response()->noContent();
        }

        $url = parse_url($data['u']);

        // Asset/feed URLs are not pages: crawlers and platform pollers (Shopify
        // apps fetching /products.json every 2 min, seen live 2026-08-28) run
        // the page's JS context or replay beacon URLs and pollute stats. Drop
        // pageviews and their pings for them; custom events keep their payload.
        $event = $data['e'] ?? null;
        if (($event === null || $event === '__ping')
            && preg_match('/\.(?:js|mjs|css|json|xml|rss|atom|txt|ico|png|jpe?g|gif|svg|webp|avif|woff2?|ttf|otf|eot|map|webmanifest|pdf|zip)$/i', $url['path'] ?? '/')) {
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
            'event_props' => $data['p'] ?? null,
            // key omitted entirely unless consented, so inserts still work pre-migration
        ] + ($tier2 && ! empty($data['i']) ? ['visitor_id' => $data['i']] : []));

        return response()->noContent();
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
