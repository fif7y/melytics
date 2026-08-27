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
                            Stats::pathMatch($w, $g->path_pattern);
                        }
                    });
                }
            })
            ->groupBy('visitor_id')
            ->selectRaw('visitor_id, MIN(created_at) as first_conv')
            ->get();

        [$f, $t] = Stats::utcBounds($from, $to);

        return $rows
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
}
