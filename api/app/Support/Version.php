<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

// Instance version + update discovery. Release zips carry a VERSION file
// (written by deploy/build-release.sh); git checkouts don't and report "dev",
// which disables update banners entirely — git installs update via git pull.
class Version
{
    public const REPO = 'fif7y/melytics';

    public static function current(): string
    {
        static $v = null;

        return $v ??= trim((string) @file_get_contents(base_path('VERSION'))) ?: 'dev';
    }

    // Latest published release. Cached 1h (the hourly scheduler also refreshes
    // it, so a warm cache never hides a new release for long); a failed lookup
    // is remembered 10 min so an unreachable GitHub doesn't stall every request
    // for the 5s timeout. Returns ['version' => '0.2.0', 'url' => ...] or null
    // (dev install, GitHub unreachable, no releases yet).
    public static function latest(bool $fresh = false): ?array
    {
        if (self::current() === 'dev') {
            return null;
        }
        $key = 'melytics.latest_release';
        if ($fresh) {
            Cache::forget($key);
        }
        $cached = Cache::get($key);
        if (is_array($cached)) {
            return $cached;
        }
        if ($cached === 'unreachable') {
            return null;
        }

        $latest = null;
        try {
            $r = Http::withUserAgent('melytics-update-check')->timeout(5)
                ->get('https://api.github.com/repos/'.self::REPO.'/releases/latest');
            if ($r->ok() && $r->json('tag_name')) {
                $latest = ['version' => ltrim($r->json('tag_name'), 'v'), 'url' => $r->json('html_url')];
            }
        } catch (\Throwable) {
        }
        Cache::put($key, $latest ?? 'unreachable', $latest ? now()->addHour() : now()->addMinutes(10));

        return $latest;
    }

    // ['latest' => ..., 'url' => ...] when a newer release exists, else null.
    public static function updateAvailable(bool $fresh = false): ?array
    {
        $latest = self::latest($fresh);

        return $latest && version_compare($latest['version'], self::current(), '>')
            ? ['latest' => $latest['version'], 'url' => $latest['url']]
            : null;
    }
}
