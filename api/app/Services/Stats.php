<?php

namespace App\Services;

use App\Models\Goal;
use App\Models\Site;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class Stats
{
    public function overview(Site $site, Carbon $from, Carbon $to, string $interval): array
    {
        $days = (int) $from->diffInDays($to) + 1;
        $prevFrom = $from->copy()->subDays($days);
        $prevTo = $from->copy()->subDay();

        return [
            'series' => $this->series($site->id, $from, $to, $interval),
            'previous_series' => $this->series($site->id, $prevFrom, $prevTo, $interval),
            'totals' => $this->totals($site->id, $from, $to),
            'previous_totals' => $this->totals($site->id, $prevFrom, $prevTo),
            'range' => ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'interval' => $interval],
        ];
    }

    public function breakdown(Site $site, string $dimension, Carbon $from, Carbon $to, int $limit = 20)
    {
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

    /** Conversion counts for each goal over the range, with rate vs total visitors. */
    public function goals(Site $site, Carbon $from, Carbon $to): array
    {
        $visitors = max($this->totals($site->id, $from, $to)['visitors'], 1);

        return $site->goals->map(function (Goal $goal) use ($site, $from, $to, $visitors) {
            $q = DB::table('hits')->where('site_id', $site->id)
                ->whereBetween('created_at', [$from, $to->copy()->endOfDay()]);
            if ($goal->event) {
                $q->where('event', $goal->event);
            } else {
                $q->whereNull('event')->where('path', 'like', str_replace('*', '%', $goal->path_pattern));
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
                ->whereBetween('created_at', [$from, $to->copy()->endOfDay()]);
            if (! empty($step['event'])) {
                $q->where('event', $step['event']);
            } else {
                $q->whereNull('event')->where('path', 'like', str_replace('*', '%', $step['path_pattern'] ?? '/'));
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
            ->whereBetween('created_at', [$from, $to->copy()->endOfDay()])
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

    public function series(int $siteId, Carbon $from, Carbon $to, string $interval)
    {
        $table = $interval === 'hour' ? 'rollup_hourly' : 'rollup_daily';
        $col = $interval === 'hour' ? 'ts' : 'day';

        return DB::table($table)
            ->where('site_id', $siteId)
            ->where('dimension', 'total')
            ->whereBetween($col, $interval === 'hour'
                ? [$from->toDateTimeString(), $to->copy()->endOfDay()->toDateTimeString()]
                : [$from->toDateString(), $to->toDateString()])
            ->orderBy($col)
            ->select([$col.' as t', 'pageviews', 'visitors'])
            ->get();
    }

    public function totals(int $siteId, Carbon $from, Carbon $to): array
    {
        $row = DB::table('rollup_daily')
            ->where('site_id', $siteId)
            ->where('dimension', 'total')
            ->whereBetween('day', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('COALESCE(SUM(pageviews),0) as pageviews, COALESCE(SUM(visitors),0) as visitors')
            ->first();

        return ['pageviews' => (int) $row->pageviews, 'visitors' => (int) $row->visitors];
    }

    /** @return array{0: Carbon, 1: Carbon, 2: string} */
    public function range(?string $from, ?string $to, ?string $interval = null): array
    {
        $toC = Carbon::parse($to ?? now()->toDateString())->startOfDay();
        $fromC = Carbon::parse($from ?? now()->subDays(29)->toDateString())->startOfDay();

        return [$fromC, $toC, $interval ?? ($fromC->diffInDays($toC) <= 2 ? 'hour' : 'day')];
    }
}
