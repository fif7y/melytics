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

    /** Every dimension the breakdown endpoint accepts. */
    public static function breakdownDimensions(): array
    {
        return [...array_keys(self::FILTERABLE), 'entry_page', 'exit_page', ...array_keys(self::EVENT_DIMENSIONS), 'channel'];
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
     * entry/exit resolve in the same aggregation pass via MIN/MAX over
     * 'created_at|path' (created_at is a fixed 19-char 'Y-m-d H:i:s', so the
     * path starts at char 21). This MUST stay deterministic: a value that flips
     * between GROUP BY and SELECT splits groups and the rollup insert then
     * violates its unique key (seen live 2026-08-28 with a LIMIT-1 subquery).
     * Named bindings: :site, :lookback, :to (+ :fval when $filterDimension given).
     */
    public static function sessionSql(?string $filterDimension = null): string
    {
        $epoch = SqlDialect::epoch(...);
        $filter = $filterDimension ? ' AND '.self::FILTERABLE[$filterDimension].' = :fval' : '';
        $tsPath = SqlDialect::concat('created_at', "'|'", 'path');

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
                   MAX(CASE WHEN event IS NULL THEN created_at END) AS last_pv,
                   SUBSTR(MIN(CASE WHEN event IS NULL THEN $tsPath END), 21) AS entry_path,
                   SUBSTR(MAX(CASE WHEN event IS NULL THEN $tsPath END), 21) AS exit_path
            FROM mg GROUP BY visitor_hash, sid
        ), sp AS (
            SELECT sess.* FROM sess WHERE sess.pageviews > 0
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
                $col = $dimension === 'not_found' ? 'path' : SqlDialect::jsonUrl();
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
            $c = ChannelClassifier::classify($r->value ?: null);
            $out[$c] = [
                'value' => $c,
                'pageviews' => ($out[$c]['pageviews'] ?? 0) + $r->pageviews,
                'visitors' => ($out[$c]['visitors'] ?? 0) + $r->visitors,
            ];
        }

        return collect(array_values($out))->sortByDesc('pageviews')->values();
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
    public static function utcBounds(Carbon $from, Carbon $to): array
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
            $conversions = (clone $q)->distinct()->count('visitor_hash');

            // Revenue = sum of the goal's numeric value prop over every matching
            // event hit (total money), with avg per converting visitor. The key
            // was charset-validated on write; re-checked here before it hits SQL.
            $revenue = $avg = null;
            if ($goal->event && $goal->value_prop && preg_match('/^[A-Za-z0-9_-]{1,64}$/', $goal->value_prop)) {
                $expr = SqlDialect::jsonNum($goal->value_prop);
                $revenue = (float) (clone $q)->selectRaw("COALESCE(SUM($expr), 0) as s")->value('s');
                $avg = $conversions ? round($revenue / $conversions, 2) : null;
            }

            return [
                'id' => $goal->id,
                'name' => $goal->name,
                'event' => $goal->event,
                'path_pattern' => $goal->path_pattern,
                'value_prop' => $goal->value_prop,
                'conversions' => $conversions,
                'rate' => round($conversions / $visitors * 100, 1),
                'revenue' => $revenue,
                'avg' => $avg,
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
        $base = fn () => DB::table('hits')
            ->where('site_id', $site->id)
            ->where('event', '__vitals')
            ->whereBetween('created_at', self::utcBounds($from, $to));

        $keys = ['lcp', 'cls', 'inp', 'ttfb'];
        $exprs = array_combine($keys, array_map(SqlDialect::jsonNum(...), $keys));

        // one aggregate pass for sample counts, then one ordered pick per metric —
        // the p75 sort happens in SQL instead of loading every props blob into PHP
        $counts = $base()->selectRaw(
            'COUNT(*) as samples, '.implode(', ', array_map(
                fn ($k) => "COUNT({$exprs[$k]}) as {$k}_n", $keys
            ))
        )->first();

        $p75 = function (string $key) use ($base, $exprs, $counts): ?float {
            $n = (int) $counts->{$key.'_n'};
            if (! $n) {
                return null;
            }
            $off = min((int) floor($n * 0.75), $n - 1);
            $v = $base()->whereRaw("{$exprs[$key]} IS NOT NULL")
                ->orderByRaw($exprs[$key])
                ->offset($off)->limit(1)
                ->selectRaw("{$exprs[$key]} as v")
                ->value('v');

            return $v === null ? null : (float) $v;
        };

        return [
            'samples' => (int) $counts->samples,
            'lcp' => $p75('lcp'),
            'cls' => $p75('cls'),
            'inp' => $p75('inp'),
            'ttfb' => $p75('ttfb'),
        ];
    }

    /** @param array{dimension: string, value: string}|null $filter */
    public function series(int $siteId, Carbon $from, Carbon $to, string $interval, ?array $filter = null, int $offsetMin = 0)
    {
        if ($filter) {
            $expr = SqlDialect::periodExpr('created_at', $interval, $offsetMin);

            $q = $this->filteredHits($siteId, $from, $to, $filter);
            if ($filter['dimension'] !== 'event') {
                $q->whereNull('event');
            }

            return self::zeroFill(
                $q->groupByRaw($expr)
                    ->orderByRaw($expr)
                    ->selectRaw("$expr as t, COUNT(*) as pageviews, COUNT(DISTINCT visitor_hash) as visitors")
                    ->get(),
                $from, $to, $interval
            );
        }

        $table = $interval === 'hour' ? 'rollup_hourly' : 'rollup_daily';
        $col = $interval === 'hour' ? 'ts' : 'day';

        return self::zeroFill(
            DB::table($table)
                ->where('site_id', $siteId)
                ->where('dimension', 'total')
                ->whereBetween($col, $interval === 'hour'
                    ? [$from->toDateTimeString(), $to->copy()->endOfDay()->toDateTimeString()]
                    : [$from->toDateString(), $to->toDateString()])
                ->orderBy($col)
                ->select([$col.' as t', 'pageviews', 'visitors', 'sessions', 'bounces', 'duration_sum'])
                ->get(),
            $from, $to, $interval
        );
    }

    /**
     * Fill missing buckets with zeros across the requested range so sparse
     * history charts at the requested width (and dead days dip to zero
     * instead of the line bridging over them). Buckets in the future — past
     * the site-local "now" — are not emitted.
     */
    private static function zeroFill($rows, Carbon $from, Carbon $to, string $interval)
    {
        $fmt = $interval === 'hour' ? 'Y-m-d H:00:00' : 'Y-m-d';
        $byT = $rows->keyBy('t');
        $zero = array_merge(
            array_fill_keys($rows->first() ? array_keys((array) $rows->first()) : ['pageviews', 'visitors'], 0),
            []
        );
        // $from carries the site timezone (see range()); Carbon comparisons are
        // absolute instants, so "now" must be tz-aware too — offset-shifting a UTC
        // Carbon lands offsetMin early and truncates the tail of today's chart.
        $nowLocal = now($from->getTimezone());
        $end = ($interval === 'hour' ? $to->copy()->endOfDay() : $to->copy())->min($nowLocal);

        $out = collect();
        for ($cur = $from->copy(); $cur <= $end; $cur->add($interval === 'hour' ? '1 hour' : '1 day')) {
            $t = $cur->format($fmt);
            $out->push($byT->get($t) ?? (object) array_merge($zero, ['t' => $t]));
        }

        return $out;
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
    /**
     * Blocked bot traffic over the range: total + top offender names. Reads
     * bot_hits (written at ingest when a beacon is rejected) — bots never
     * touch hits or rollups. Rows use value/pageviews so the SPA can render
     * them like any breakdown. Table may not exist yet after a code-only
     * deploy; the card just reads empty until the migration runs.
     */
    public function bots(Site $site, Carbon $from, Carbon $to, int $limit = 8): array
    {
        try {
            $q = DB::table('bot_hits')
                ->where('site_id', $site->id)
                ->whereBetween('created_at', self::utcBounds($from, $to));

            return [
                'total' => (clone $q)->count(),
                'names' => (clone $q)->groupBy('name')
                    ->orderByRaw('COUNT(*) DESC')
                    ->limit($limit)
                    ->selectRaw('name as value, COUNT(*) as pageviews')
                    ->get(),
            ];
        } catch (\Throwable) {
            return ['total' => 0, 'names' => []];
        }
    }

    /**
     * Distinct property keys seen on a custom event over the range, each tagged
     * 'number' or 'string' by whether its values are mostly numeric. Powers the
     * Event properties card's prop dropdown. json_each (SQLite) / JSON_TABLE
     * (MySQL) enumerate object keys across rows.
     *
     * @return array<int, array{key: string, type: string}>
     */
    public function eventPropKeys(Site $site, string $event, Carbon $from, Carbon $to, int $limit = 50): array
    {
        [$lo, $hi] = self::utcBounds($from, $to);
        $mysql = DB::connection()->getDriverName() === 'mysql';

        $sql = $mysql
            ? "SELECT jt.k AS `key`,
                      SUM(CASE WHEN JSON_TYPE(JSON_EXTRACT(h.event_props, CONCAT('$.', jt.k))) IN ('INTEGER','DOUBLE','DECIMAL','UNSIGNED INTEGER') THEN 1 ELSE 0 END) AS nums,
                      COUNT(*) AS total
               FROM hits h, JSON_TABLE(JSON_KEYS(h.event_props), '$[*]' COLUMNS (k VARCHAR(64) PATH '$')) jt
               WHERE h.site_id = ? AND h.event = ? AND h.created_at BETWEEN ? AND ? AND h.event_props IS NOT NULL
               GROUP BY jt.k ORDER BY total DESC LIMIT ?"
            : "SELECT je.key AS key,
                      SUM(CASE WHEN typeof(je.value) IN ('integer','real') THEN 1 ELSE 0 END) AS nums,
                      COUNT(*) AS total
               FROM hits h, json_each(h.event_props) je
               WHERE h.site_id = ? AND h.event = ? AND h.created_at BETWEEN ? AND ? AND h.event_props IS NOT NULL
               GROUP BY je.key ORDER BY total DESC LIMIT ?";

        return array_map(
            fn ($r) => ['key' => $r->key, 'type' => ($r->nums > 0 && $r->nums * 2 >= $r->total) ? 'number' : 'string'],
            DB::select($sql, [$site->id, $event, $lo->toDateTimeString(), $hi->toDateTimeString(), $limit])
        );
    }

    /**
     * One property on a custom event, over the range (scans raw hits — props
     * aren't rolled up). $prop/$by are pre-validated by the caller to a safe
     * key charset. Three shapes:
     * - string prop, no $by: value distribution — rows {value, count, visitors}.
     * - numeric prop, no $by: single aggregate — {sum, avg, count, min, max}.
     * - numeric $prop grouped by string $by: rows {value, sum, avg, count}.
     */
    public function eventProps(Site $site, string $event, string $prop, ?string $by, Carbon $from, Carbon $to, int $limit = 20): array
    {
        $base = fn () => DB::table('hits')
            ->where('site_id', $site->id)
            ->where('event', $event)
            ->whereNotNull('event_props')
            ->whereBetween('created_at', self::utcBounds($from, $to));

        $num = SqlDialect::jsonNum($prop);

        if ($by !== null && $by !== '') {
            $byCol = SqlDialect::jsonStr($by);

            return [
                'type' => 'numeric', 'prop' => $prop, 'by' => $by,
                'rows' => $base()
                    ->whereRaw("$num IS NOT NULL")
                    ->groupByRaw($byCol)
                    ->orderByRaw("SUM($num) DESC")
                    ->limit($limit)
                    ->selectRaw("$byCol as value, SUM($num) as sum, AVG($num) as avg, COUNT($num) as count")
                    ->get(),
            ];
        }

        // No group-by: numeric prop -> one aggregate; otherwise string distribution.
        if ($base()->whereRaw("$num IS NOT NULL")->exists()) {
            $a = $base()->selectRaw("SUM($num) as sum, AVG($num) as avg, COUNT($num) as count, MIN($num) as min, MAX($num) as max")->first();

            return [
                'type' => 'aggregate', 'prop' => $prop,
                'sum' => (float) $a->sum, 'avg' => (float) $a->avg, 'count' => (int) $a->count,
                'min' => (float) $a->min, 'max' => (float) $a->max,
            ];
        }

        $str = SqlDialect::jsonStr($prop);

        return [
            'type' => 'string', 'prop' => $prop,
            'rows' => $base()
                ->whereRaw("$str IS NOT NULL")
                ->groupByRaw($str)
                ->orderByRaw('COUNT(*) DESC')
                ->limit($limit)
                ->selectRaw("$str as value, COUNT(*) as count, COUNT(DISTINCT visitor_hash) as visitors")
                ->get(),
        ];
    }

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
}
