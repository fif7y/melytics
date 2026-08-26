<?php

namespace App\Services;

use App\Models\Goal;
use App\Models\Site;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class Stats
{
    /** Dimensions that can filter the whole dashboard, mapped to their hits column. */
    public const FILTERABLE = [
        'page' => 'path',
        'referrer' => 'referrer_host',
        'country' => 'country',
        'device' => 'device',
        'browser' => 'browser',
        'os' => 'os',
        'utm_source' => 'utm_source',
        'utm_medium' => 'utm_medium',
        'utm_campaign' => 'utm_campaign',
        'event' => 'event',
    ];

    /** Referrer-host needles per channel; AI before Search so gemini.google.com lands in AI. */
    private const CHANNEL_HOSTS = [
        'AI' => ['chatgpt.com', 'chat.openai.com', 'perplexity.ai', 'claude.ai', 'gemini.google.com', 'copilot.microsoft.com', 'you.com', 'phind.com', 'poe.com'],
        'Search' => ['google.', 'bing.com', 'duckduckgo.com', 'search.yahoo.com', 'ecosia.org', 'search.brave.com', 'startpage.com', 'baidu.com', 'yandex.'],
        'Social' => ['twitter.com', 'x.com', 't.co', 'facebook.com', 'fb.com', 'instagram.com', 'linkedin.com', 'reddit.com', 'news.ycombinator.com', 'lobste.rs', 'threads.net', 'bsky.app', 'mastodon', 'tiktok.com', 'youtube.com', 'pinterest.'],
        'Email' => ['mail.google.com', 'outlook.live.com', 'mail.yahoo.com', 'mail.proton.me'],
    ];

    public static function channel(?string $host): string
    {
        if (! $host) {
            return 'Direct';
        }
        foreach (self::CHANNEL_HOSTS as $channel => $needles) {
            foreach ($needles as $needle) {
                if (str_contains($host, $needle)) {
                    return $channel;
                }
            }
        }

        return 'Referral';
    }

    /** Breakdown dimensions derived from internal events rather than hit columns. */
    public const EVENT_DIMENSIONS = [
        'outbound' => '__outbound',
        'download' => '__download',
        'not_found' => '__404',
    ];

    /**
     * WITH-clause prefix yielding session-level rows in `sp`: hits grouped per
     * visitor split on >30-min inactivity gaps. Heartbeat pings extend duration
     * but never count as pageviews; ping-only sessions are dropped, so first_pv
     * (the session's first pageview) is always set — sessions bucket by it.
     * Named bindings: :site, :lookback, :to (+ :fval when $filterDimension given).
     */
    public static function sessionSql(?string $filterDimension = null): string
    {
        $driver = DB::connection()->getDriverName();
        $epoch = fn (string $x) => $driver === 'mysql' ? "UNIX_TIMESTAMP($x)" : "CAST(strftime('%s', $x) AS INTEGER)";
        $filter = $filterDimension ? ' AND '.self::FILTERABLE[$filterDimension].' = :fval' : '';

        return "WITH mh AS (
            SELECT visitor_hash, created_at, path, event,
                   CASE WHEN LAG(created_at) OVER w IS NULL
                        OR {$epoch('created_at')} - {$epoch('LAG(created_at) OVER w')} > 1800
                        THEN 1 ELSE 0 END AS s0
            FROM hits
            WHERE site_id = :site AND created_at >= :lookback AND created_at <= :to
              AND (event IS NULL OR event = '__ping')$filter
            WINDOW w AS (PARTITION BY visitor_hash ORDER BY created_at)
        ), mg AS (
            SELECT mh.*, SUM(s0) OVER (PARTITION BY visitor_hash ORDER BY created_at) AS sid FROM mh
        ), sess AS (
            SELECT visitor_hash, sid,
                   {$epoch('MAX(created_at)')} - {$epoch('MIN(created_at)')} AS duration,
                   SUM(CASE WHEN event IS NULL THEN 1 ELSE 0 END) AS pageviews,
                   MIN(CASE WHEN event IS NULL THEN created_at END) AS first_pv,
                   MAX(CASE WHEN event IS NULL THEN created_at END) AS last_pv
            FROM mg GROUP BY visitor_hash, sid
        ), sp AS (
            SELECT sess.*,
                   (SELECT path FROM mg WHERE mg.visitor_hash = sess.visitor_hash AND mg.sid = sess.sid
                     AND mg.created_at = sess.first_pv AND mg.event IS NULL LIMIT 1) AS entry_path,
                   (SELECT path FROM mg WHERE mg.visitor_hash = sess.visitor_hash AND mg.sid = sess.sid
                     AND mg.created_at = sess.last_pv AND mg.event IS NULL LIMIT 1) AS exit_path
            FROM sess WHERE sess.pageviews > 0
        ) ";
    }

    /** @param array{dimension: string, value: string}|null $filter */
    public function overview(Site $site, Carbon $from, Carbon $to, string $interval, ?array $filter = null): array
    {
        $days = (int) $from->diffInDays($to) + 1;
        $prevFrom = $from->copy()->subDays($days);
        $prevTo = $from->copy()->subDay();

        $off = self::tzOffset($site->timezone);

        return [
            'series' => $this->series($site->id, $from, $to, $interval, $filter, $off),
            'previous_series' => $this->series($site->id, $prevFrom, $prevTo, $interval, $filter, $off),
            'totals' => $this->totals($site->id, $from, $to, $filter),
            'previous_totals' => $this->totals($site->id, $prevFrom, $prevTo, $filter),
            'range' => ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'interval' => $interval],
        ];
    }

    /** @param array{dimension: string, value: string}|null $filter */
    public function breakdown(Site $site, string $dimension, Carbon $from, Carbon $to, int $limit = 20, ?array $filter = null)
    {
        if ($dimension === 'channel') {
            return $this->channelBreakdown($site, $from, $to, $filter);
        }
        if ($filter) {
            if (in_array($dimension, ['entry_page', 'exit_page'], true)) {
                return $this->filteredSessionBreakdown($site->id, $dimension, $from, $to, $limit, $filter);
            }
            if (isset(self::EVENT_DIMENSIONS[$dimension])) {
                $col = $dimension === 'not_found' ? 'path' : $this->jsonUrlExpr();
                return $this->filteredHits($site->id, $from, $to, $filter)
                    ->where('event', self::EVENT_DIMENSIONS[$dimension])
                    ->groupByRaw($col)
                    ->orderByRaw('COUNT(*) DESC')
                    ->limit($limit)
                    ->selectRaw("$col as value, COUNT(*) as pageviews, COUNT(DISTINCT visitor_hash) as visitors")
                    ->get();
            }

            $col = self::FILTERABLE[$dimension];
            $q = $this->filteredHits($site->id, $from, $to, $filter);
            if ($dimension === 'event') {
                $q->whereNotNull('event')->whereRaw("substr(event, 1, 2) != '__'");
            } elseif ($filter['dimension'] !== 'event') {
                $q->whereNull('event');
            }

            return $q->whereNotNull($col)->where($col, '!=', '')
                ->groupBy($col)
                ->orderByRaw('COUNT(*) DESC')
                ->limit($limit)
                ->selectRaw("$col as value, COUNT(*) as pageviews, COUNT(DISTINCT visitor_hash) as visitors")
                ->get();
        }

        return DB::table('rollup_daily')
            ->where('site_id', $site->id)
            ->whereBetween('day', [$from->toDateString(), $to->toDateString()])
            ->where('dimension', $dimension)
            ->where('value', '!=', '')
            ->groupBy('value')
            ->orderByRaw('SUM(pageviews) DESC')
            ->limit($limit)
            ->selectRaw('value, SUM(pageviews) as pageviews, SUM(visitors) as visitors')
            ->get();
    }

    /** Traffic grouped into Direct / Search / Social / AI / Email / Referral by referrer host. */
    private function channelBreakdown(Site $site, Carbon $from, Carbon $to, ?array $filter)
    {
        if ($filter) {
            $q = $this->filteredHits($site->id, $from, $to, $filter);
            if ($filter['dimension'] !== 'event') {
                $q->whereNull('event');
            }
            $rows = $q->groupBy('referrer_host')
                ->selectRaw('referrer_host as value, COUNT(*) as pageviews, COUNT(DISTINCT visitor_hash) as visitors')
                ->get();
        } else {
            // referrer rollup including the '' rows — those are direct traffic
            $rows = DB::table('rollup_daily')
                ->where('site_id', $site->id)
                ->whereBetween('day', [$from->toDateString(), $to->toDateString()])
                ->where('dimension', 'referrer')
                ->groupBy('value')
                ->selectRaw('value, SUM(pageviews) as pageviews, SUM(visitors) as visitors')
                ->get();
        }

        $out = [];
        foreach ($rows as $r) {
            $c = self::channel($r->value ?: null);
            $out[$c] = [
                'value' => $c,
                'pageviews' => ($out[$c]['pageviews'] ?? 0) + $r->pageviews,
                'visitors' => ($out[$c]['visitors'] ?? 0) + $r->visitors,
            ];
        }

        return collect(array_values($out))->sortByDesc('pageviews')->values();
    }

    /** JSON url extractor for __outbound / __download event props, per driver. */
    private function jsonUrlExpr(): string
    {
        return DB::connection()->getDriverName() === 'mysql'
            ? "JSON_UNQUOTE(JSON_EXTRACT(event_props, '$.url'))"
            : "json_extract(event_props, '$.url')";
    }

    /** Entry/exit pages under a cross-filter: sessions rebuilt from raw hits. */
    private function filteredSessionBreakdown(int $siteId, string $dimension, Carbon $from, Carbon $to, int $limit, array $filter)
    {
        if ($filter['dimension'] === 'event') {
            return collect(); // custom events are excluded from session hits
        }
        $col = $dimension === 'entry_page' ? 'entry_path' : 'exit_path';

        return collect(DB::select(
            self::sessionSql($filter['dimension'])."
            SELECT $col as value, COUNT(*) as pageviews, COUNT(DISTINCT visitor_hash) as visitors
            FROM sp GROUP BY $col ORDER BY COUNT(*) DESC LIMIT $limit",
            [
                'site' => $siteId,
                'lookback' => $from->copy()->utc()->toDateTimeString(),
                'to' => $to->copy()->endOfDay()->utc()->toDateTimeString(),
                'fval' => $filter['value'],
            ]
        ));
    }

    /** Range bounds as UTC instants — hits.created_at is stored in UTC. */
    private static function utcBounds(Carbon $from, Carbon $to): array
    {
        return [$from->copy()->utc(), $to->copy()->endOfDay()->utc()];
    }

    /** Raw-hits base query for a cross-filter (rollups are per-dimension, so filtered stats must scan hits). */
    private function filteredHits(int $siteId, Carbon $from, Carbon $to, array $filter)
    {
        return DB::table('hits')
            ->where('site_id', $siteId)
            ->whereBetween('created_at', self::utcBounds($from, $to))
            ->where(self::FILTERABLE[$filter['dimension']], $filter['value']);
    }

    /** Conversion counts for each goal over the range, with rate vs total visitors. */
    /**
     * Trailing-slash-insensitive path matching for goals/funnels: '/about'
     * matches '/about/' and vice versa; '*' is a wildcard as before.
     */
    public static function pathMatch($q, string $pattern): void
    {
        $like = str_replace('*', '%', $pattern);
        if (str_ends_with($like, '%')) {
            $q->where('path', 'like', $like);
        } else {
            $like = rtrim($like, '/') ?: '/';
            $q->where(fn ($w) => $w->where('path', 'like', $like)->orWhere('path', 'like', $like.'/'));
        }
    }

    public function goals(Site $site, Carbon $from, Carbon $to): array
    {
        $visitors = max($this->totals($site->id, $from, $to)['visitors'], 1);

        return $site->goals->map(function (Goal $goal) use ($site, $from, $to, $visitors) {
            $q = DB::table('hits')->where('site_id', $site->id)
                ->whereBetween('created_at', self::utcBounds($from, $to));
            if ($goal->event) {
                $q->where('event', $goal->event);
            } else {
                $q->whereNull('event');
                self::pathMatch($q, $goal->path_pattern);
            }
            $conversions = $q->distinct()->count('visitor_hash');

            return [
                'id' => $goal->id,
                'name' => $goal->name,
                'event' => $goal->event,
                'path_pattern' => $goal->path_pattern,
                'conversions' => $conversions,
                'rate' => round($conversions / $visitors * 100, 1),
            ];
        })->all();
    }

    /**
     * Per-step visitor counts for a funnel, requiring steps in order:
     * a visitor counts for step N only if they matched steps 1..N-1 first,
     * each at or before the time they matched step N.
     *
     * @param  array<int, array{name?: string, event?: ?string, path_pattern?: ?string}>  $steps
     * @return array<int, array{name: string, visitors: int, rate: float}>
     */
    public function funnel(Site $site, array $steps, Carbon $from, Carbon $to): array
    {
        // visitor_hash => earliest time they reached the previous step
        $reached = null;
        $entered = 1;
        $out = [];

        foreach ($steps as $i => $step) {
            $q = DB::table('hits')->where('site_id', $site->id)
                ->whereBetween('created_at', self::utcBounds($from, $to));
            if (! empty($step['event'])) {
                $q->where('event', $step['event']);
            } else {
                $q->whereNull('event');
                self::pathMatch($q, $step['path_pattern'] ?? '/');
            }
            $times = $q->groupBy('visitor_hash')
                ->selectRaw('visitor_hash, MIN(created_at) as t')
                ->pluck('t', 'visitor_hash');

            if ($reached !== null) {
                $times = $times->filter(fn ($t, $v) => isset($reached[$v]) && $t >= $reached[$v]);
            }
            $reached = $times->all();

            $count = count($reached);
            if ($i === 0) {
                $entered = max($count, 1);
            }
            $out[] = [
                'name' => $step['name'] ?? ($step['event'] ?? $step['path_pattern'] ?? 'step '.($i + 1)),
                'visitors' => $count,
                'rate' => round($count / $entered * 100, 1),
            ];
        }

        return $out;
    }

    /**
     * p75 Web Vitals from '__vitals' events over the range.
     *
     * @return array{samples: int, lcp: ?float, cls: ?float, inp: ?float, ttfb: ?float}
     */
    public function vitals(Site $site, Carbon $from, Carbon $to): array
    {
        $rows = DB::table('hits')
            ->where('site_id', $site->id)
            ->where('event', '__vitals')
            ->whereBetween('created_at', self::utcBounds($from, $to))
            ->pluck('event_props');

        $metrics = ['lcp' => [], 'cls' => [], 'inp' => [], 'ttfb' => []];
        foreach ($rows as $json) {
            $p = json_decode($json, true) ?: [];
            foreach ($metrics as $key => $_) {
                if (isset($p[$key]) && is_numeric($p[$key])) {
                    $metrics[$key][] = (float) $p[$key];
                }
            }
        }

        $p75 = function (array $vals): ?float {
            if (! $vals) {
                return null;
            }
            sort($vals);
            return $vals[(int) floor(count($vals) * 0.75)] ?? end($vals);
        };

        return [
            'samples' => count($rows),
            'lcp' => $p75($metrics['lcp']),
            'cls' => $p75($metrics['cls']),
            'inp' => $p75($metrics['inp']),
            'ttfb' => $p75($metrics['ttfb']),
        ];
    }

    /**
     * Tier-2 retention: consented visitors in range, split new vs returning.
     * Returning = visitor_id also seen before the range start.
     *
     * @return array{identified: int, new: int, returning: int}
     */
    public function retention(Site $site, Carbon $from, Carbon $to): array
    {
        $ids = DB::table('hits')
            ->where('site_id', $site->id)
            ->whereBetween('created_at', self::utcBounds($from, $to))
            ->whereNotNull('visitor_id')
            ->distinct()
            ->pluck('visitor_id');

        $returning = $ids->isEmpty() ? 0 : DB::table('hits')
            ->where('site_id', $site->id)
            ->where('created_at', '<', $from->copy()->utc())
            ->whereIn('visitor_id', $ids)
            ->distinct()
            ->count('visitor_id');

        return [
            'identified' => $ids->count(),
            'new' => $ids->count() - $returning,
            'returning' => $returning,
        ];
    }

    /**
     * Tier-2 weekly cohorts: consented visitors grouped by first-seen week
     * (site-local, Monday-based), with distinct visitors active 1..n weeks later.
     *
     * @return array<int, array{week: string, size: int, active: array<int, int>}>
     */
    public function cohorts(Site $site, int $weeks = 8): array
    {
        $off = self::tzOffset($site->timezone);
        $driver = DB::connection()->getDriverName();
        // Week index since Monday 2024-01-01, in site-local time
        $wk = $driver === 'mysql'
            ? "FLOOR(DATEDIFF(DATE_ADD(created_at, INTERVAL $off MINUTE), '2024-01-01') / 7)"
            : "CAST((julianday(date(datetime(created_at, '$off minutes'))) - julianday('2024-01-01')) / 7 AS INTEGER)";

        $rows = DB::select(
            "SELECT visitor_id, $wk AS wk FROM hits
             WHERE site_id = ? AND visitor_id IS NOT NULL
             GROUP BY visitor_id, wk",
            [$site->id]
        );

        $first = [];
        $active = [];
        foreach ($rows as $r) {
            $first[$r->visitor_id] = min($first[$r->visitor_id] ?? PHP_INT_MAX, (int) $r->wk);
            $active[$r->visitor_id][] = (int) $r->wk;
        }

        $nowWk = intdiv((int) Carbon::parse('2024-01-01')->diffInDays(now($site->timezone)), 7);
        $startWk = $nowWk - $weeks + 1;

        $cohorts = [];
        for ($w = $startWk; $w <= $nowWk; $w++) {
            $cohorts[$w] = [
                'week' => Carbon::parse('2024-01-01')->addWeeks($w)->toDateString(),
                'size' => 0,
                'active' => array_fill(0, $nowWk - $w + 1, 0),
            ];
        }
        foreach ($first as $id => $w) {
            if ($w < $startWk) {
                continue;
            }
            $cohorts[$w]['size']++;
            foreach (array_unique($active[$id]) as $aw) {
                $cohorts[$w]['active'][$aw - $w]++;
            }
        }

        return array_values($cohorts);
    }

    /**
     * Tier-2 loyalty: visits (30-min-gap sessions) per consented visitor in range,
     * bucketed into a frequency distribution.
     *
     * @return array{identified: int, avg: float, buckets: array<int, array{label: string, visitors: int}>}
     */
    public function loyalty(Site $site, Carbon $from, Carbon $to): array
    {
        [$f, $t] = self::utcBounds($from, $to);
        $driver = DB::connection()->getDriverName();
        $epoch = fn (string $x) => $driver === 'mysql' ? "UNIX_TIMESTAMP($x)" : "CAST(strftime('%s', $x) AS INTEGER)";

        $rows = DB::select(
            "WITH v AS (
                SELECT visitor_id,
                       CASE WHEN LAG(created_at) OVER w IS NULL
                            OR {$epoch('created_at')} - {$epoch('LAG(created_at) OVER w')} > 1800
                            THEN 1 ELSE 0 END AS s0
                FROM hits
                WHERE site_id = ? AND visitor_id IS NOT NULL AND created_at BETWEEN ? AND ?
                WINDOW w AS (PARTITION BY visitor_id ORDER BY created_at)
            ) SELECT SUM(s0) AS visits FROM v GROUP BY visitor_id",
            [$site->id, $f, $t]
        );

        $buckets = [
            ['label' => '1 visit', 'min' => 1, 'max' => 1, 'visitors' => 0],
            ['label' => '2', 'min' => 2, 'max' => 2, 'visitors' => 0],
            ['label' => '3–5', 'min' => 3, 'max' => 5, 'visitors' => 0],
            ['label' => '6–10', 'min' => 6, 'max' => 10, 'visitors' => 0],
            ['label' => '11+', 'min' => 11, 'max' => PHP_INT_MAX, 'visitors' => 0],
        ];
        $total = 0;
        foreach ($rows as $r) {
            $v = (int) $r->visits;
            $total += $v;
            foreach ($buckets as &$b) {
                if ($v >= $b['min'] && $v <= $b['max']) {
                    $b['visitors']++;
                    break;
                }
            }
            unset($b);
        }

        return [
            'identified' => count($rows),
            'avg' => count($rows) ? round($total / count($rows), 1) : 0.0,
            'buckets' => array_map(fn ($b) => ['label' => $b['label'], 'visitors' => $b['visitors']], $buckets),
        ];
    }

    /**
     * Consented visitors whose first hit matching any goal falls inside the range.
     *
     * @return array<string, string> visitor_id => first conversion time (UTC)
     */
    private function convertingVisitors(Site $site, Carbon $from, Carbon $to): array
    {
        $goals = $site->goals;
        if ($goals->isEmpty()) {
            return [];
        }

        $rows = DB::table('hits')
            ->where('site_id', $site->id)
            ->whereNotNull('visitor_id')
            ->where(function ($q) use ($goals) {
                foreach ($goals as $g) {
                    $q->orWhere(function ($w) use ($g) {
                        if ($g->event) {
                            $w->where('event', $g->event);
                        } else {
                            $w->whereNull('event');
                            self::pathMatch($w, $g->path_pattern);
                        }
                    });
                }
            })
            ->groupBy('visitor_id')
            ->selectRaw('visitor_id, MIN(created_at) as first_conv')
            ->get();

        [$f, $t] = self::utcBounds($from, $to);

        return $rows
            ->filter(fn ($r) => $r->first_conv >= $f->toDateTimeString() && $r->first_conv <= $t->toDateTimeString())
            ->pluck('first_conv', 'visitor_id')
            ->all();
    }

    /**
     * Tier-2 first-touch attribution: converting consented visitors credited to
     * the channel of their first-ever visit (not the converting session).
     *
     * @return array{identified: int, channels: array<int, array{channel: string, visitors: int}>}
     */
    public function attribution(Site $site, Carbon $from, Carbon $to): array
    {
        $conv = $this->convertingVisitors($site, $from, $to);
        if (! $conv) {
            return ['identified' => 0, 'channels' => []];
        }

        // SQLite: bare columns follow the MIN(id) row (same trick as live())
        $firsts = DB::table('hits')
            ->where('site_id', $site->id)
            ->whereIn('visitor_id', array_keys($conv))
            ->groupBy('visitor_id')
            ->selectRaw('visitor_id, referrer_host, MIN(id)')
            ->get();

        $out = [];
        foreach ($firsts as $r) {
            $c = self::channel($r->referrer_host ?: null);
            $out[$c] = ($out[$c] ?? 0) + 1;
        }
        arsort($out);

        return [
            'identified' => count($conv),
            'channels' => array_map(fn ($c, $n) => ['channel' => $c, 'visitors' => $n], array_keys($out), $out),
        ];
    }

    /**
     * Tier-2 time to conversion: days and sessions between a consented visitor's
     * first-ever visit and their first goal hit.
     *
     * @return array{identified: int, median_days: float, median_sessions: float, buckets: array<int, array{label: string, visitors: int}>}
     */
    public function timeToConvert(Site $site, Carbon $from, Carbon $to): array
    {
        $buckets = [
            ['label' => 'Same day', 'max' => 1, 'visitors' => 0],
            ['label' => '1–7d', 'max' => 7, 'visitors' => 0],
            ['label' => '8–30d', 'max' => 30, 'visitors' => 0],
            ['label' => '30d+', 'max' => PHP_FLOAT_MAX, 'visitors' => 0],
        ];
        $conv = $this->convertingVisitors($site, $from, $to);
        if (! $conv) {
            return ['identified' => 0, 'median_days' => 0.0, 'median_sessions' => 0.0,
                'buckets' => array_map(fn ($b) => ['label' => $b['label'], 'visitors' => 0], $buckets)];
        }

        $hits = DB::table('hits')
            ->where('site_id', $site->id)
            ->whereIn('visitor_id', array_keys($conv))
            ->orderBy('created_at')
            ->get(['visitor_id', 'created_at'])
            ->groupBy('visitor_id');

        $days = [];
        $sessions = [];
        foreach ($conv as $id => $firstConv) {
            $ts = $hits[$id]->pluck('created_at')->filter(fn ($t) => $t <= $firstConv)->values();
            $days[] = (strtotime($firstConv) - strtotime($ts[0])) / 86400;
            $n = 1;
            for ($i = 1; $i < count($ts); $i++) {
                if (strtotime($ts[$i]) - strtotime($ts[$i - 1]) > 1800) {
                    $n++;
                }
            }
            $sessions[] = $n;
        }
        foreach ($days as $d) {
            foreach ($buckets as &$b) {
                if ($d < $b['max']) {
                    $b['visitors']++;
                    break;
                }
            }
            unset($b);
        }

        $median = function (array $vals): float {
            sort($vals);
            $n = count($vals);
            return $n % 2 ? $vals[intdiv($n, 2)] : ($vals[$n / 2 - 1] + $vals[$n / 2]) / 2;
        };

        return [
            'identified' => count($conv),
            'median_days' => round($median($days), 1),
            'median_sessions' => round($median($sessions), 1),
            'buckets' => array_map(fn ($b) => ['label' => $b['label'], 'visitors' => $b['visitors']], $buckets),
        ];
    }

    /** @param array{dimension: string, value: string}|null $filter */
    public function series(int $siteId, Carbon $from, Carbon $to, string $interval, ?array $filter = null, int $offsetMin = 0)
    {
        if ($filter) {
            $expr = self::periodExpr('created_at', $interval, $offsetMin);

            $q = $this->filteredHits($siteId, $from, $to, $filter);
            if ($filter['dimension'] !== 'event') {
                $q->whereNull('event');
            }

            return $q->groupByRaw($expr)
                ->orderByRaw($expr)
                ->selectRaw("$expr as t, COUNT(*) as pageviews, COUNT(DISTINCT visitor_hash) as visitors")
                ->get();
        }

        $table = $interval === 'hour' ? 'rollup_hourly' : 'rollup_daily';
        $col = $interval === 'hour' ? 'ts' : 'day';

        return DB::table($table)
            ->where('site_id', $siteId)
            ->where('dimension', 'total')
            ->whereBetween($col, $interval === 'hour'
                ? [$from->toDateTimeString(), $to->copy()->endOfDay()->toDateTimeString()]
                : [$from->toDateString(), $to->toDateString()])
            ->orderBy($col)
            ->select([$col.' as t', 'pageviews', 'visitors', 'sessions', 'bounces', 'duration_sum'])
            ->get();
    }

    /** @param array{dimension: string, value: string}|null $filter */
    public function totals(int $siteId, Carbon $from, Carbon $to, ?array $filter = null): array
    {
        if ($filter) {
            $q = $this->filteredHits($siteId, $from, $to, $filter);
            if ($filter['dimension'] !== 'event') {
                $q->whereNull('event');
            }

            return [
                'pageviews' => (clone $q)->count(),
                'visitors' => (clone $q)->distinct()->count('visitor_hash'),
            ] + $this->filteredSessionTotals($siteId, $from, $to, $filter);
        }

        $row = DB::table('rollup_daily')
            ->where('site_id', $siteId)
            ->where('dimension', 'total')
            ->whereBetween('day', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('COALESCE(SUM(pageviews),0) as pageviews, COALESCE(SUM(visitors),0) as visitors,'
                .' COALESCE(SUM(sessions),0) as sessions, COALESCE(SUM(bounces),0) as bounces,'
                .' COALESCE(SUM(duration_sum),0) as duration_sum')
            ->first();

        return [
            'pageviews' => (int) $row->pageviews,
            'visitors' => (int) $row->visitors,
        ] + self::sessionMetrics((int) $row->sessions, (int) $row->bounces, (int) $row->duration_sum);
    }

    /** @return array{sessions: int, bounce_rate: ?float, avg_duration: ?int} */
    private static function sessionMetrics(int $sessions, int $bounces, int $duration): array
    {
        return [
            'sessions' => $sessions,
            'bounce_rate' => $sessions ? round($bounces / $sessions * 100, 1) : null,
            'avg_duration' => $sessions ? (int) round($duration / $sessions) : null,
        ];
    }

    /** Session totals under a cross-filter, rebuilt from raw hits. */
    private function filteredSessionTotals(int $siteId, Carbon $from, Carbon $to, array $filter): array
    {
        if ($filter['dimension'] === 'event') {
            return self::sessionMetrics(0, 0, 0); // custom events are excluded from session hits
        }
        $row = DB::selectOne(
            self::sessionSql($filter['dimension'])."
            SELECT COUNT(*) as s, COALESCE(SUM(CASE WHEN pageviews = 1 THEN 1 ELSE 0 END),0) as b,
                   COALESCE(SUM(duration),0) as d
            FROM sp",
            [
                'site' => $siteId,
                'lookback' => $from->copy()->utc()->toDateTimeString(),
                'to' => $to->copy()->endOfDay()->utc()->toDateTimeString(),
                'fval' => $filter['value'],
            ]
        );

        return self::sessionMetrics((int) $row->s, (int) $row->b, (int) $row->d);
    }

    /**
     * Date params are interpreted in the site's timezone — rollups are keyed by
     * site-local time, so "today" flips at the site's midnight, not UTC's.
     *
     * @return array{0: Carbon, 1: Carbon, 2: string}
     */
    public function range(?string $from, ?string $to, ?string $interval = null, string $tz = 'UTC'): array
    {
        $toC = Carbon::parse($to ?? now($tz)->toDateString(), $tz)->startOfDay();
        $fromC = Carbon::parse($from ?? now($tz)->subDays(29)->toDateString(), $tz)->startOfDay();

        return [$fromC, $toC, $interval ?? ($fromC->diffInDays($toC) <= 2 ? 'hour' : 'day')];
    }

    /** Site-local offset from UTC in minutes, as of now (rollup bucketing uses the same). */
    public static function tzOffset(string $tz): int
    {
        return now($tz)->utcOffset();
    }

    /** Period expression bucketing a UTC datetime column into site-local time. */
    public static function periodExpr(string $column, string $grain, int $offsetMin): string
    {
        $driver = DB::connection()->getDriverName();
        $shifted = $offsetMin === 0 ? $column
            : ($driver === 'mysql'
                ? "DATE_ADD($column, INTERVAL $offsetMin MINUTE)"
                : "datetime($column, '$offsetMin minutes')");

        return $grain === 'hour'
            ? ($driver === 'mysql' ? "DATE_FORMAT($shifted, '%Y-%m-%d %H:00:00')" : "strftime('%Y-%m-%d %H:00:00', $shifted)")
            : ($driver === 'mysql' ? "DATE($shifted)" : "date($shifted)");
    }
}
