<?php

namespace App\Services;

use App\Models\Site;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Data behind the chart layouts (v0.5): hour×weekday grid, per-value series
 * for the mix / trend / bump forms, session-duration buckets, and the
 * referrer → landing page → outcome flow. Everything here is read from the
 * rollups except durations and paths, which scan raw hits like funnels do.
 */
class ChartStats
{
    /**
     * Visitors per (weekday, hour) over the range from the hourly rollups,
     * plus today's hourly curve — both in site-local time, as the rollups are
     * keyed. `days` lets the client average the grid per weekday column.
     *
     * @return array{grid: int[][], today: int[], days: int}
     */
    public function hours(Site $site, Carbon $from, Carbon $to): array
    {
        $wd = SqlDialect::weekday('ts');
        $hr = SqlDialect::hour('ts');
        $rows = DB::table('rollup_hourly')
            ->where('site_id', $site->id)
            ->where('dimension', 'total')
            ->whereBetween('ts', [$from->toDateTimeString(), $to->copy()->endOfDay()->toDateTimeString()])
            ->groupByRaw("$wd, $hr")
            ->selectRaw("$wd as wd, $hr as hr, SUM(visitors) as v")
            ->get();

        // Monday-first rows (0 = Mon … 6 = Sun)
        $grid = array_fill(0, 7, array_fill(0, 24, 0));
        foreach ($rows as $r) {
            $grid[((int) $r->wd + 6) % 7][(int) $r->hr] = (int) $r->v;
        }

        $today = array_fill(0, 24, 0);
        $day = now($site->timezone)->toDateString();
        foreach (DB::table('rollup_hourly')
            ->where('site_id', $site->id)->where('dimension', 'total')
            ->where('ts', '>=', "$day 00:00:00")->where('ts', '<=', "$day 23:59:59")
            ->selectRaw("$hr as hr, visitors")->get() as $r) {
            $today[(int) $r->hr] = (int) $r->visitors;
        }

        return ['grid' => $grid, 'today' => $today, 'days' => (int) $from->diffInDays($to) + 1];
    }

    /**
     * Per-bucket series for the top values of one dimension (+ the remainder as
     * "other"), the data behind the stacked-mix and bump layouts. Buckets follow
     * the range's interval so a 7d range reads hourly like the main chart does.
     *
     * @return array{buckets: string[], series: array<int, array{value: string, points: int[]}>, other: int[]}
     */
    public function mix(Site $site, string $dim, Carbon $from, Carbon $to, string $interval, int $n = 5): array
    {
        [$table, $col, $bounds] = self::period($from, $to, $interval);
        $top = DB::table($table)
            ->where('site_id', $site->id)->where('dimension', $dim)->where('value', '!=', '')
            ->whereBetween($col, $bounds)
            ->groupBy('value')->orderByRaw('SUM(pageviews) DESC')->limit($n)
            ->selectRaw('value')->get()->map(fn ($r) => (string) $r->value)->all();

        $buckets = self::buckets($from, $to, $interval);
        $series = $this->valueSeries($site->id, $dim, $top, $from, $to, $interval, $buckets);

        // "other" = everything under this dimension that isn't a top value
        $idx = array_flip($buckets);
        $other = array_fill(0, count($buckets), 0);
        $all = DB::table($table)
            ->where('site_id', $site->id)->where('dimension', $dim)->where('value', '!=', '')
            ->whereBetween($col, $bounds)
            ->groupBy($col)->selectRaw("$col as t, SUM(pageviews) as pv")->get();
        foreach ($all as $r) {
            $t = self::label((string) $r->t, $interval);
            if (isset($idx[$t])) {
                $other[$idx[$t]] = (int) $r->pv;
            }
        }
        foreach ($series as $s) {
            foreach ($s['points'] as $i => $v) {
                $other[$i] = max(0, $other[$i] - $v);
            }
        }

        return ['buckets' => $buckets, 'series' => array_values($series), 'other' => $other];
    }

    /**
     * Pageviews per bucket for named values of a dimension — the sparkline
     * behind each row of a breakdown in its Trend layout.
     *
     * @return array<string, int[]> value => points aligned to buckets()
     */
    public function trend(Site $site, string $dim, array $values, Carbon $from, Carbon $to, string $interval): array
    {
        $buckets = self::buckets($from, $to, $interval);
        $out = [];
        foreach ($this->valueSeries($site->id, $dim, $values, $from, $to, $interval, $buckets) as $s) {
            $out[$s['value']] = $s['points'];
        }

        return $out;
    }

    /** @return array<int, array{value: string, points: int[]}> in the order of $values */
    private function valueSeries(int $siteId, string $dim, array $values, Carbon $from, Carbon $to, string $interval, array $buckets): array
    {
        if (! $values) {
            return [];
        }
        [$table, $col, $bounds] = self::period($from, $to, $interval);
        $idx = array_flip($buckets);
        $series = [];
        foreach ($values as $v) {
            $series[$v] = ['value' => $v, 'points' => array_fill(0, count($buckets), 0)];
        }
        $rows = DB::table($table)
            ->where('site_id', $siteId)->where('dimension', $dim)->whereIn('value', $values)
            ->whereBetween($col, $bounds)
            ->select([$col.' as t', 'value', 'pageviews'])->get();
        foreach ($rows as $r) {
            $t = self::label((string) $r->t, $interval);
            if (isset($idx[$t], $series[$r->value])) {
                $series[$r->value]['points'][$idx[$t]] += (int) $r->pageviews;
            }
        }

        return array_values($series);
    }

    /** @return array{0: string, 1: string, 2: array{0: string, 1: string}} table, period column, whereBetween bounds */
    private static function period(Carbon $from, Carbon $to, string $interval): array
    {
        return $interval === 'hour'
            ? ['rollup_hourly', 'ts', [$from->toDateTimeString(), $to->copy()->endOfDay()->toDateTimeString()]]
            : ['rollup_daily', 'day', [$from->toDateString(), $to->toDateString()]];
    }

    private static function label(string $t, string $interval): string
    {
        return $interval === 'hour' ? substr($t, 0, 13).':00:00' : substr($t, 0, 10);
    }

    /** Every bucket label in the range up to the site-local now, same rule as Stats::zeroFill. */
    public static function buckets(Carbon $from, Carbon $to, string $interval): array
    {
        $fmt = $interval === 'hour' ? 'Y-m-d H:00:00' : 'Y-m-d';
        $end = ($interval === 'hour' ? $to->copy()->endOfDay() : $to->copy())->min(now($from->getTimezone()));
        $out = [];
        for ($cur = $from->copy(); $cur <= $end; $cur->add($interval === 'hour' ? '1 hour' : '1 day')) {
            $out[] = $cur->format($fmt);
        }

        return $out;
    }

    /** Session durations in fixed buckets (seconds), from the same session split the rollups use. */
    public const DURATION_BUCKETS = [
        ['0–10s', 0, 10], ['10–30s', 10, 30], ['30s–1m', 30, 60], ['1–2m', 60, 120],
        ['2–5m', 120, 300], ['5–10m', 300, 600], ['10m+', 600, null],
    ];

    /** @return array{sessions: int, median: ?int, buckets: array<int, array{label: string, sessions: int}>} */
    public function durations(Site $site, Carbon $from, Carbon $to): array
    {
        $case = 'CASE ';
        foreach (self::DURATION_BUCKETS as $i => [, $lo, $hi]) {
            $case .= $hi === null ? "WHEN duration >= $lo THEN $i " : "WHEN duration < $hi THEN $i ";
        }
        $case .= 'END';
        $rows = DB::select(
            Stats::sessionSql()." SELECT $case as b, COUNT(*) as n FROM sp GROUP BY $case",
            self::sessionBindings($site, $from, $to)
        );
        $counts = array_fill(0, count(self::DURATION_BUCKETS), 0);
        $total = 0;
        foreach ($rows as $r) {
            $counts[(int) $r->b] = (int) $r->n;
            $total += (int) $r->n;
        }
        $median = null;
        if ($total) {
            $median = (int) DB::selectOne(
                Stats::sessionSql().' SELECT duration FROM sp ORDER BY duration LIMIT 1 OFFSET '.intdiv($total, 2),
                self::sessionBindings($site, $from, $to)
            )->duration;
        }

        return [
            'sessions' => $total,
            'median' => $median,
            'buckets' => array_map(fn ($b, $i) => ['label' => $b[0], 'sessions' => $counts[$i]], self::DURATION_BUCKETS, array_keys(self::DURATION_BUCKETS)),
        ];
    }

    /**
     * Sessions grouped by (referrer of the first pageview, landing page,
     * outcome) for the flow layout. Outcome: converted when any goal hit lands
     * in the session window, else bounced (one pageview) or browsed. The client
     * folds sources / landings past the top few into "Other".
     *
     * @return array<int, array{source: string, landing: string, outcome: string, sessions: int}>
     */
    public function paths(Site $site, Carbon $from, Carbon $to): array
    {
        $bindings = self::sessionBindings($site, $from, $to);
        $conv = '0';
        $conds = [];
        foreach ($site->goals as $i => $g) {
            if ($g->event) {
                $conds[] = "g.event = :ge$i";
                $bindings["ge$i"] = $g->event;
            } elseif ($g->path_pattern) {
                $like = str_replace('*', '%', $g->path_pattern);
                if (str_ends_with($like, '%')) {
                    $conds[] = "g.path LIKE :gp$i";
                    $bindings["gp$i"] = $like;
                } else {
                    $like = rtrim($like, '/') ?: '/';
                    $conds[] = "(g.path LIKE :gp$i OR g.path LIKE :gq$i)";
                    $bindings["gp$i"] = $like;
                    $bindings["gq$i"] = $like.'/';
                }
            }
        }
        if ($conds) {
            $conv = 'EXISTS (SELECT 1 FROM hits g WHERE g.site_id = :gsite AND g.visitor_hash = sp.visitor_hash'
                .' AND g.created_at >= sp.first_pv AND g.created_at <= sp.last_pv AND ('.implode(' OR ', $conds).'))';
            $bindings['gsite'] = $site->id;
        }

        $rows = DB::select(
            Stats::sessionSql()."
            , fh AS (
                SELECT sp.*, MIN(COALESCE(h.referrer_host, '')) AS src,
                       CASE WHEN $conv THEN 'converted' WHEN sp.pageviews = 1 THEN 'bounced' ELSE 'browsed' END AS outcome
                FROM sp
                JOIN hits h ON h.site_id = :hsite AND h.visitor_hash = sp.visitor_hash AND h.created_at = sp.first_pv AND h.event IS NULL
                GROUP BY sp.visitor_hash, sp.sid, sp.entry_path, sp.pageviews, sp.first_pv, sp.last_pv, sp.duration, sp.exit_path
            )
            SELECT src, entry_path, outcome, COUNT(*) AS n FROM fh GROUP BY src, entry_path, outcome ORDER BY n DESC LIMIT 400",
            $bindings + ['hsite' => $site->id]
        );

        return array_map(fn ($r) => [
            'source' => $r->src === '' ? 'Direct' : $r->src,
            'landing' => $r->entry_path,
            'outcome' => $r->outcome,
            'sessions' => (int) $r->n,
        ], $rows);
    }

    private static function sessionBindings(Site $site, Carbon $from, Carbon $to): array
    {
        return [
            'site' => $site->id,
            'lookback' => $from->copy()->utc()->toDateTimeString(),
            'to' => $to->copy()->endOfDay()->utc()->toDateTimeString(),
        ];
    }
}
