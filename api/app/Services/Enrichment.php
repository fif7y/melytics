<?php

namespace App\Services;

use Illuminate\Http\Request;

class Enrichment
{
    /**
     * Daily-rotating visitor hash: hash(site, ip, ua, salt-of-the-day).
     * Inputs are never stored; the hash cannot be reversed or linked across days.
     */
    public function visitorHash(int $siteId, Request $request): string
    {
        $salt = date('Y-m-d').config('app.key');

        return substr(hash('sha256', $siteId.'|'.$request->ip().'|'.$request->userAgent().'|'.$salt), 0, 16);
    }

    public function isBot(?string $ua): bool
    {
        if ($ua === null || $ua === '') {
            return true;
        }

        return (bool) preg_match(
            '/bot|crawl|spider|slurp|bingpreview|facebookexternalhit|whatsapp|telegram|discord|pingdom|uptime|monitor|headless|phantom|lighthouse|pagespeed|gtmetrix|curl|wget|python-requests|axios|go-http-client|okhttp/i',
            $ua
        );
    }

    /** Display label for a blocked UA: a recognizable crawler name when there is one. */
    public function botName(?string $ua): string
    {
        if ($ua === null || $ua === '') {
            return 'No user agent';
        }
        if (preg_match(
            '/(Googlebot|AdsBot|bingbot|BingPreview|DuckDuckBot|YandexBot|Baiduspider|AhrefsBot|SemrushBot|MJ12bot|DotBot|PetalBot|GPTBot|OAI-SearchBot|ClaudeBot|anthropic-ai|PerplexityBot|CCBot|Bytespider|Applebot|facebookexternalhit|Twitterbot|LinkedInBot|WhatsApp|Telegram|Discord|Slackbot|Pinterest|UptimeRobot|Pingdom|StatusCake|HeadlessChrome|Lighthouse|PageSpeed|GTmetrix|curl|Wget|python-requests|Go-http-client|okhttp|axios|PhantomJS)/i',
            $ua, $m
        )) {
            return $m[1];
        }
        // Unrecognized but self-declared: keep the token that tripped the filter
        if (preg_match('/([A-Za-z0-9._-]*(?:bot|crawler|spider)[A-Za-z0-9._-]*)/i', $ua, $m)) {
            return $m[1];
        }

        return 'Other bot';
    }

    /** @return array{device:string,browser:string,os:string} */
    public function parseUserAgent(?string $ua): array
    {
        $ua = $ua ?? '';

        $device = 'desktop';
        if (preg_match('/ipad|tablet|(android(?!.*mobile))/i', $ua)) {
            $device = 'tablet';
        } elseif (preg_match('/mobi|iphone|ipod|android/i', $ua)) {
            $device = 'mobile';
        }

        $browser = match (true) {
            str_contains($ua, 'Edg/') => 'Edge',
            str_contains($ua, 'OPR/') || str_contains($ua, 'Opera') => 'Opera',
            str_contains($ua, 'SamsungBrowser') => 'Samsung Internet',
            str_contains($ua, 'Firefox/') => 'Firefox',
            str_contains($ua, 'Chrome/') || str_contains($ua, 'CriOS') => 'Chrome',
            str_contains($ua, 'Safari/') => 'Safari',
            default => 'Other',
        };

        $os = match (true) {
            str_contains($ua, 'Windows') => 'Windows',
            str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'iPhone') || str_contains($ua, 'iPad') || str_contains($ua, 'iOS') => 'iOS',
            str_contains($ua, 'Mac OS X') => 'macOS',
            str_contains($ua, 'CrOS') => 'ChromeOS',
            str_contains($ua, 'Linux') => 'Linux',
            default => 'Other',
        };

        return compact('device', 'browser', 'os');
    }

    /**
     * Country resolution: CDN headers first (free + accurate), then a local
     * mmdb (MaxMind GeoLite2 or DB-IP Lite) if present at storage/geoip/GeoLite2-Country.mmdb,
     * then the visitor's IANA timezone. IP is used in-memory only and never stored.
     */
    public function country(Request $request, ?string $timezone = null): ?string
    {
        foreach (['CF-IPCountry', 'X-Vercel-IP-Country', 'X-Country-Code'] as $header) {
            $c = $request->header($header);
            if ($c && strlen($c) === 2 && $c !== 'XX') {
                return strtoupper($c);
            }
        }

        $mmdb = storage_path('geoip/GeoLite2-Country.mmdb');
        if (is_file($mmdb) && class_exists(\GeoIp2\Database\Reader::class)) {
            try {
                static $reader = null;
                $reader ??= new \GeoIp2\Database\Reader($mmdb);

                return $reader->country($request->ip())->country->isoCode;
            } catch (\Throwable) {
            }
        }

        if ($timezone) {
            try {
                $cc = (new \DateTimeZone($timezone))->getLocation()['country_code'] ?? null;

                return ($cc && $cc !== '??') ? $cc : null;
            } catch (\Throwable) {
            }
        }

        return null;
    }
}
