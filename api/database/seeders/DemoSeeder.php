<?php

namespace Database\Seeders;

use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Seeds a realistic 90-day demo dataset (demo@melytics.dev / demopass1234,
 * site demo.melytics.dev). Deterministic (fixed RNG seed). Re-runnable:
 * wipes and regenerates the demo site's data. After seeding, run
 * `php artisan melytics:rollup --hours=2200` to build the rollups.
 */
class DemoSeeder extends Seeder
{
    private const TZ = 'America/Toronto';

    private const DAYS = 90;

    private const HN_SPIKE_DAY = 11;   // days ago — lands inside the default 30-day range

    private const V2_DAY = 5;

    public function run(): void
    {
        mt_srand(4242);

        $user = User::firstOrCreate(
            ['email' => 'demo@melytics.dev'],
            ['name' => 'Demo', 'password' => 'demopass1234', 'email_verified_at' => now()]
        );
        $site = $user->sites()->firstOrCreate(
            ['domain' => 'demo.melytics.dev'],
            ['name' => 'Melytics Demo', 'key' => Str::random(24), 'timezone' => self::TZ, 'tier2_enabled' => true]
        );
        $site->update(['tier2_enabled' => true, 'timezone' => self::TZ]);

        foreach (['hits', 'rollup_hourly', 'rollup_daily', 'goals', 'funnels', 'annotations'] as $t) {
            DB::table($t)->where('site_id', $site->id)->delete();
        }

        $site->goals()->createMany([
            ['name' => 'Signup', 'event' => 'signup'],
            ['name' => 'Purchase', 'event' => 'purchase'],
            ['name' => 'Visited pricing', 'path_pattern' => '/pricing'],
        ]);
        $site->funnels()->create([
            'name' => 'Signup funnel',
            'steps' => [
                ['name' => 'Home', 'path_pattern' => '/'],
                ['name' => 'Pricing', 'path_pattern' => '/pricing'],
                ['name' => 'Signup page', 'path_pattern' => '/signup'],
                ['name' => 'Signed up', 'event' => 'signup'],
            ],
        ]);
        $site->funnels()->create([
            'name' => 'Docs adoption',
            'steps' => [
                ['name' => 'Home', 'path_pattern' => '/'],
                ['name' => 'Docs', 'path_pattern' => '/docs'],
                ['name' => 'Install guide', 'path_pattern' => '/docs/install'],
            ],
        ]);
        DB::table('annotations')->insert([
            ['site_id' => $site->id, 'day' => now(self::TZ)->subDays(self::HN_SPIKE_DAY)->toDateString(), 'text' => 'Launched on Hacker News', 'created_at' => now(), 'updated_at' => now()],
            ['site_id' => $site->id, 'day' => now(self::TZ)->subDays(self::V2_DAY)->toDateString(), 'text' => 'v2.0 released', 'created_at' => now(), 'updated_at' => now()],
            ['site_id' => $site->id, 'day' => now(self::TZ)->subDays(52)->toDateString(), 'text' => 'Weekly digest shipped', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $rows = [];
        $consentPool = [];   // visitor_id => last day index seen
        $flush = function () use (&$rows) {
            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('hits')->insert($chunk);
            }
            $rows = [];
        };

        $nowLocal = now(self::TZ);
        for ($d = self::DAYS - 1; $d >= 0; $d--) {
            $day = $nowLocal->copy()->startOfDay()->subDays($d);
            $growth = 130 + (self::DAYS - 1 - $d) * 1.5;
            $weekend = in_array($day->dayOfWeek, [0, 6]) ? 0.6 : 1.0;
            $spike = $d === self::HN_SPIKE_DAY ? 4.2 : ($d === self::HN_SPIKE_DAY - 1 ? 1.8 : 1.0);
            $bump = $d <= self::V2_DAY ? 1.25 : 1.0;
            $visitors = (int) round($growth * $weekend * $spike * $bump * $this->rand(0.85, 1.15));

            for ($v = 0; $v < $visitors; $v++) {
                $this->seedVisit($rows, $site->id, $day, $d, $nowLocal, $consentPool);
            }

            // Web Vitals samples (~8/day)
            for ($s = 0; $s < 8; $s++) {
                $ts = $this->timeInDay($day, $d, $nowLocal);
                if (! $ts) {
                    continue;
                }
                $rows[] = $this->hit($site->id, bin2hex(random_bytes(8)), '/', null, $ts, [
                    'event' => '__vitals',
                    'event_props' => json_encode([
                        'lcp' => round($this->rand(900, 2600)),
                        'cls' => round($this->rand(0.01, 0.12), 3),
                        'inp' => round($this->rand(60, 280)),
                        'ttfb' => round($this->rand(90, 520)),
                    ]),
                ]);
            }

            if (count($rows) > 4000) {
                $flush();
            }
        }

        // Live now: a few visitors mid-session with heartbeats
        foreach ([['/', 1], ['/blog/cookieless-analytics', 2], ['/docs/install', 3], ['/pricing', 1]] as [$path, $min]) {
            $h = bin2hex(random_bytes(8));
            $ts = now()->subMinutes($min + 1);
            $rows[] = $this->hit($site->id, $h, $path, 'news.ycombinator.com', $ts, []);
            $rows[] = $this->hit($site->id, $h, $path, null, now()->subSeconds($min * 20), ['event' => '__ping']);
        }
        $flush();

        $this->command?->info('Demo data seeded. Now run: php artisan melytics:rollup --hours='.((self::DAYS + 2) * 24));
    }

    private function seedVisit(array &$rows, int $siteId, Carbon $day, int $d, Carbon $nowLocal, array &$consentPool): void
    {
        $ts = $this->timeInDay($day, $d, $nowLocal);
        if (! $ts) {
            return;
        }

        $hash = bin2hex(random_bytes(8));
        $visitorId = null;
        if ($this->chance(35)) {   // tier-2 consented
            if ($consentPool && $this->chance(45)) {
                $visitorId = array_rand($consentPool);
            } else {
                $visitorId = Str::random(21);
            }
            $consentPool[$visitorId] = $d;
        }

        $referrer = $this->pick([
            [null, 38], ['google.com', 22], ['news.ycombinator.com', $d === self::HN_SPIKE_DAY ? 45 : 6],
            ['t.co', 7], ['github.com', 8], ['reddit.com', 4], ['bing.com', 3],
            ['chat.openai.com', 4], ['www.linkedin.com', 3], ['duckduckgo.com', 3], ['dev.to', 2],
        ]);
        [$utmS, $utmM, $utmC] = $this->chance(9)
            ? $this->pick([[['newsletter', 'email', 'weekly-digest'], 60], [['producthunt', 'social', 'launch'], 40]])
            : [null, null, null];

        $device = $this->pick([['desktop', 58], ['mobile', 36], ['tablet', 6]]);
        $browser = $this->pick([['Chrome', 46], ['Safari', 28], ['Firefox', 12], ['Edge', 9], ['Samsung Internet', 5]]);
        $os = $device === 'desktop'
            ? $this->pick([['macOS', 48], ['Windows', 40], ['Linux', 12]])
            : $this->pick([['iOS', 55], ['Android', 45]]);
        $screen = $device === 'desktop' ? $this->pick([[1920, 40], [1440, 35], [2560, 15], [1280, 10]])
            : ($device === 'mobile' ? $this->pick([[390, 50], [414, 30], [360, 20]]) : 820);
        $country = $this->pick([
            ['US', 34], ['CA', 14], ['GB', 10], ['DE', 8], ['FR', 6], ['IN', 7],
            ['AU', 5], ['NL', 4], ['JP', 4], ['BR', 4], ['SE', 2], ['ES', 2],
        ]);

        $base = ['referrer_host' => $referrer, 'utm_source' => $utmS, 'utm_medium' => $utmM,
            'utm_campaign' => $utmC, 'country' => $country, 'device' => $device,
            'browser' => $browser, 'os' => $os, 'screen_w' => $screen, 'visitor_id' => $visitorId];

        $entry = $this->pick([
            ['/', 34], ['/blog/cookieless-analytics', 12], ['/blog/goodbye-google-analytics', 9],
            ['/pricing', 10], ['/docs', 9], ['/blog/web-vitals-explained', 6],
            ['/changelog', 5], ['/docs/install', 6], ['/about', 4], ['/blog', 5],
        ]);

        $pages = $this->pick([[1, 42], [2, 24], [3, 16], [4, 10], [5, 5], [6, 3]]);
        $path = $entry;
        $t = $ts->copy();
        $rows[] = $this->hit($siteId, $hash, $path, $referrer, $t, $base);

        $journey = ['/' => ['/pricing', '/docs', '/blog', '/about'],
            '/pricing' => ['/signup', '/docs', '/'],
            '/docs' => ['/docs/install', '/docs/events', '/docs/goals'],
            '/blog' => ['/blog/cookieless-analytics', '/blog/goodbye-google-analytics', '/blog/web-vitals-explained'],
        ];
        $signedUp = false;
        for ($p = 1; $p < $pages; $p++) {
            $t->addSeconds(mt_rand(35, 220));
            $next = $journey[$path] ?? $journey['/'];
            $path = $next[mt_rand(0, count($next) - 1)];
            // subsequent pageviews carry no referrer (internal navigation)
            $rows[] = $this->hit($siteId, $hash, $path, null, $t, array_merge($base, ['referrer_host' => null]));

            if ($path === '/signup' && $this->chance(55)) {
                $t->addSeconds(mt_rand(20, 90));
                $rows[] = $this->hit($siteId, $hash, '/signup', null, $t, array_merge($base, [
                    'referrer_host' => null, 'event' => 'signup',
                ]));
                $signedUp = true;
                $path = '/welcome';
                $t->addSeconds(mt_rand(5, 20));
                $rows[] = $this->hit($siteId, $hash, $path, null, $t, array_merge($base, ['referrer_host' => null]));
            }
        }

        if ($signedUp && $this->chance(30)) {
            $t->addSeconds(mt_rand(60, 400));
            [$plan, $value] = $this->pick([[['pro', 29], 70], [['team', 79], 30]]);
            $rows[] = $this->hit($siteId, $hash, '/welcome', null, $t, array_merge($base, [
                'referrer_host' => null, 'event' => 'purchase',
                'event_props' => json_encode(['plan' => $plan, 'value' => $value]),
            ]));
        }
        if ($this->chance(6)) {
            $t->addSeconds(mt_rand(10, 60));
            $rows[] = $this->hit($siteId, $hash, $path, null, $t, array_merge($base, [
                'referrer_host' => null, 'event' => '__outbound',
                'event_props' => json_encode(['url' => 'https://github.com/fif7y/melytics']),
            ]));
        }
    }

    /** Diurnal local time inside $day, converted to UTC; null if in the future. */
    private function timeInDay(Carbon $day, int $d, Carbon $nowLocal): ?Carbon
    {
        $hour = $this->pick([
            [mt_rand(0, 5), 4], [mt_rand(6, 8), 10], [mt_rand(9, 11), 22], [mt_rand(12, 14), 20],
            [mt_rand(15, 17), 20], [mt_rand(18, 20), 15], [mt_rand(21, 23), 9],
        ]);
        $local = $day->copy()->setTime($hour, mt_rand(0, 59), mt_rand(0, 59));
        if ($d === 0 && $local->gt($nowLocal->copy()->subMinutes(10))) {
            return null;
        }

        return $local->utc();
    }

    private function hit(int $siteId, string $hash, string $path, ?string $referrer, Carbon $ts, array $extra): array
    {
        return array_merge([
            'site_id' => $siteId, 'visitor_hash' => $hash, 'path' => $path,
            'referrer_host' => $referrer, 'utm_source' => null, 'utm_medium' => null,
            'utm_campaign' => null, 'country' => null, 'device' => null, 'browser' => null,
            'os' => null, 'screen_w' => null, 'event' => null, 'event_props' => null,
            'visitor_id' => null, 'created_at' => $ts->format('Y-m-d H:i:s'),
        ], $extra);
    }

    private function chance(int $pct): bool
    {
        return mt_rand(1, 100) <= $pct;
    }

    private function rand(float $min, float $max): float
    {
        return $min + ($max - $min) * mt_rand(0, 10000) / 10000;
    }

    /** Weighted pick from [[value, weight], ...]. */
    private function pick(array $options)
    {
        $total = array_sum(array_column($options, 1));
        $r = mt_rand(1, $total);
        foreach ($options as [$value, $weight]) {
            if (($r -= $weight) <= 0) {
                return $value;
            }
        }

        return $options[0][0];
    }
}
