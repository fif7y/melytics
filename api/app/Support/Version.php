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

    // Latest published release, cached half a day so /auth/me stays cheap.
    // Returns ['version' => '0.2.0', 'url' => ...] or null (dev install,
    // GitHub unreachable, no releases yet).
    public static function latest(bool $fresh = false): ?array
    {
        if (self::current() === 'dev') {
            return null;
        }
        if ($fresh) {
            Cache::forget('melytics.latest_release');
        }

        return Cache::remember('melytics.latest_release', now()->addHours(12), function () {
            try {
                $r = Http::withUserAgent('melytics-update-check')->timeout(5)
                    ->get('https://api.github.com/repos/'.self::REPO.'/releases/latest');

                return $r->ok()
                    ? ['version' => ltrim($r->json('tag_name', ''), 'v'), 'url' => $r->json('html_url')]
                    : null;
            } catch (\Throwable) {
                return null;
            }
        });
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
