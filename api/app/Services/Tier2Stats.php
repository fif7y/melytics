<?php

namespace App\Services;

use App\Models\Site;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Consent-gated analytics over the persistent visitor_id (tier-2):
 * retention, cohorts, loyalty, attribution, time-to-convert.
 */
class Tier2Stats
{
    /**
     * Retention: consented visitors in range, split new vs returning.
     * Returning = visitor_id also seen before the range start.
     *
     * @return array{identified: int, new: int, returning: int}
     */
    public function retention(Site $site, Carbon $from, Carbon $to): array
    {
        $ids = DB::table('hits')
            ->where('site_id', $site->id)
            ->whereBetween('created_at', Stats::utcBounds($from, $to))
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
     * Weekly cohorts: consented visitors grouped by first-seen week
     * (site-local, Monday-based), with distinct visitors active 1..n weeks later.
     *
     * @return array<int, array{week: string, size: int, active: array<int, int>}>
     */
    public function cohorts(Site $site, int $weeks = 8): array
    {
        $wk = SqlDialect::weekIndex('created_at', Stats::tzOffset($site->timezone));
        $nowWk = intdiv((int) Carbon::parse('2024-01-01')->diffInDays(now($site->timezone)), 7);
        $startWk = $nowWk - $weeks + 1;

        // aggregated in SQL: (cohort week, offset) → active visitors; visitors whose
        // first-seen week predates the window never reach PHP
        $rows = DB::select(
            "WITH vw AS (
                SELECT visitor_id, $wk AS wk FROM hits
                WHERE site_id = ? AND visitor_id IS NOT NULL
                GROUP BY visitor_id, wk
            ), fw AS (
                SELECT visitor_id, MIN(wk) AS cw FROM vw GROUP BY visitor_id HAVING MIN(wk) >= ?
            )
            SELECT fw.cw AS cw, vw.wk - fw.cw AS off, COUNT(*) AS n
            FROM vw JOIN fw ON fw.visitor_id = vw.visitor_id
            GROUP BY fw.cw, vw.wk - fw.cw",
            [$site->id, $startWk]
        );

        $cohorts = [];
        for ($w = $startWk; $w <= $nowWk; $w++) {
            $cohorts[$w] = [
                'week' => Carbon::parse('2024-01-01')->addWeeks($w)->toDateString(),
                'size' => 0,
                'active' => array_fill(0, $nowWk - $w + 1, 0),
            ];
        }
        foreach ($rows as $r) {
            if (! isset($cohorts[$r->cw])) {
                continue;
            }
            if ((int) $r->off === 0) {
                $cohorts[$r->cw]['size'] = (int) $r->n; // every visitor is active in their first week
            }
            $cohorts[$r->cw]['active'][(int) $r->off] = (int) $r->n;
        }

        return array_values($cohorts);
    }

    /**
     * Loyalty: visits (30-min-gap sessions) per consented visitor in range,
     * bucketed into a frequency distribution.
     *
     * @return array{identified: int, avg: float, buckets: array<int, array{label: string, visitors: int}>}
     */
    public function loyalty(Site $site, Carbon $from, Carbon $to): array
    {
        [$f, $t] = Stats::utcBounds($from, $to);
        $epoch = SqlDialect::epoch(...);

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
    /**
     * Consented visitors with their first-ever goal-matching hit, or null when
     * the site has no goals. Callers filter first_conv into their range.
     */
    private function convertingQuery(Site $site): ?\Illuminate\Database\Query\Builder
    {
        $goals = $site->goals;
        if ($goals->isEmpty()) {
            return null;
        }

        return DB::table('hits')
            ->where('site_id', $site->id)
            ->whereNotNull('visitor_id')
            ->where(function ($q) use ($goals) {
                foreach ($goals as $g) {
                    $q->orWhere(function ($w) use ($g) {
                        if ($g->event) {
                            $w->where('event', $g->event);
                        } else {
                            $w->whereNull('event');
                            Stats::pathMatch($w, $g->path_pattern);
                        }
                    });
                }
            })
            ->groupBy('visitor_id')
            ->selectRaw('visitor_id, MIN(created_at) as first_conv');
    }

    private function convertingVisitors(Site $site, Carbon $from, Carbon $to): array
    {
        $q = $this->convertingQuery($site);
        if (! $q) {
            return [];
        }

        [$f, $t] = Stats::utcBounds($from, $to);

        return $q->get()
            ->filter(fn ($r) => $r->first_conv >= $f->toDateTimeString() && $r->first_conv <= $t->toDateTimeString())
            ->pluck('first_conv', 'visitor_id')
            ->all();
    }

    /**
     * First-touch attribution: converting consented visitors credited to
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
            $c = ChannelClassifier::classify($r->referrer_host ?: null);
            $out[$c] = ($out[$c] ?? 0) + 1;
        }
        arsort($out);

        return [
            'identified' => count($conv),
            'channels' => array_map(fn ($c, $n) => ['channel' => $c, 'visitors' => $n], array_keys($out), $out),
        ];
    }

    /**
     * Time to conversion: days and sessions between a consented visitor's
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
        $convQ = $this->convertingQuery($site);
        $empty = ['identified' => 0, 'median_days' => 0.0, 'median_sessions' => 0.0,
            'buckets' => array_map(fn ($b) => ['label' => $b['label'], 'visitors' => 0], $buckets)];
        if (! $convQ) {
            return $empty;
        }

        // per-visitor days-to-convert and session count (30-min gaps), computed in
        // SQL over each visitor's hits up to their first conversion — only one
        // small row per converting visitor reaches PHP
        [$f, $t] = Stats::utcBounds($from, $to);
        $epoch = SqlDialect::epoch(...);
        $rows = DB::select(
            "WITH conv AS (
                SELECT * FROM ({$convQ->toSql()}) c WHERE first_conv BETWEEN ? AND ?
            ), h AS (
                SELECT hits.visitor_id, hits.created_at, conv.first_conv,
                       CASE WHEN LAG(hits.created_at) OVER w IS NULL
                            OR {$epoch('hits.created_at')} - {$epoch('LAG(hits.created_at) OVER w')} > 1800
                            THEN 1 ELSE 0 END AS s0
                FROM hits JOIN conv ON conv.visitor_id = hits.visitor_id AND hits.created_at <= conv.first_conv
                WHERE hits.site_id = ?
                WINDOW w AS (PARTITION BY hits.visitor_id ORDER BY hits.created_at)
            )
            SELECT ({$epoch('MAX(first_conv)')} - {$epoch('MIN(created_at)')}) / 86400.0 AS days,
                   SUM(s0) AS sessions
            FROM h GROUP BY visitor_id",
            [...$convQ->getBindings(), $f->toDateTimeString(), $t->toDateTimeString(), $site->id]
        );
        if (! $rows) {
            return $empty;
        }

        $days = array_map(fn ($r) => (float) $r->days, $rows);
        $sessions = array_map(fn ($r) => (int) $r->sessions, $rows);
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
            'identified' => count($rows),
            'median_days' => round($median($days), 1),
            'median_sessions' => round($median($sessions), 1),
            'buckets' => array_map(fn ($b) => ['label' => $b['label'], 'visitors' => $b['visitors']], $buckets),
        ];
    }
}
