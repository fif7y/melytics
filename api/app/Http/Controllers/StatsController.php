<?php

namespace App\Http\Controllers;

use App\Models\Site;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class StatsController extends Controller
{
    /**
     * GET /sites/{site}/stats?from=2026-08-01&to=2026-08-25&interval=day|hour
     * Returns the time series + totals for the range, plus the same for the
     * previous period of equal length (dashboard comparison overlay).
     */
    public function stats(Request $request, Site $site): JsonResponse
    {
        $this->authorizeSite($request, $site);
        [$from, $to, $interval] = $this->range($request);

        $days = $from->diffInDays($to) + 1;
        $prevFrom = $from->copy()->subDays($days);
        $prevTo = $from->copy()->subDay();

        return response()->json([
            'series' => $this->series($site->id, $from, $to, $interval),
            'previous_series' => $this->series($site->id, $prevFrom, $prevTo, $interval),
            'totals' => $this->totals($site->id, $from, $to),
            'previous_totals' => $this->totals($site->id, $prevFrom, $prevTo),
            'range' => ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'interval' => $interval],
        ]);
    }

    /**
     * GET /sites/{site}/breakdown?dimension=page&from=&to=&limit=20
     */
    public function breakdown(Request $request, Site $site): JsonResponse
    {
        $this->authorizeSite($request, $site);
        $dimension = $request->validate([
            'dimension' => 'required|in:page,referrer,country,device,browser,os,utm_source,utm_medium,utm_campaign,event',
        ])['dimension'];
        [$from, $to] = $this->range($request);
        $limit = min((int) $request->query('limit', 20), 100);

        $rows = DB::table('rollup_daily')
            ->where('site_id', $site->id)
            ->whereBetween('day', [$from->toDateString(), $to->toDateString()])
            ->where('dimension', $dimension)
            ->where('value', '!=', '')
            ->groupBy('value')
            ->orderByRaw('SUM(pageviews) DESC')
            ->limit($limit)
            ->selectRaw('value, SUM(pageviews) as pageviews, SUM(visitors) as visitors')
            ->get();

        return response()->json(['dimension' => $dimension, 'rows' => $rows]);
    }

    /**
     * GET /sites/{site}/live — visitors active in the last 5 minutes (raw hits).
     */
    public function live(Request $request, Site $site): JsonResponse
    {
        $this->authorizeSite($request, $site);

        $since = now()->subMinutes(5);
        $visitors = DB::table('hits')
            ->where('site_id', $site->id)
            ->where('created_at', '>=', $since)
            ->distinct()
            ->count('visitor_hash');
        $pages = DB::table('hits')
            ->where('site_id', $site->id)
            ->where('created_at', '>=', $since)
            ->whereNull('event')
            ->groupBy('path')
            ->orderByRaw('COUNT(DISTINCT visitor_hash) DESC')
            ->limit(10)
            ->selectRaw('path, COUNT(DISTINCT visitor_hash) as visitors')
            ->get();

        return response()->json(['visitors' => $visitors, 'pages' => $pages]);
    }

    private function series(int $siteId, Carbon $from, Carbon $to, string $interval)
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

    private function totals(int $siteId, Carbon $from, Carbon $to): array
    {
        $row = DB::table('rollup_daily')
            ->where('site_id', $siteId)
            ->where('dimension', 'total')
            ->whereBetween('day', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('COALESCE(SUM(pageviews),0) as pageviews, COALESCE(SUM(visitors),0) as visitors')
            ->first();

        // visitors summed across days over-counts returning visitors within the
        // range; acceptable for tier 1 (no cross-day identity exists by design).
        return ['pageviews' => (int) $row->pageviews, 'visitors' => (int) $row->visitors];
    }

    private function range(Request $request): array
    {
        $to = Carbon::parse($request->query('to', now()->toDateString()))->startOfDay();
        $from = Carbon::parse($request->query('from', now()->subDays(29)->toDateString()))->startOfDay();
        $interval = $request->query('interval', $from->diffInDays($to) <= 2 ? 'hour' : 'day');

        return [$from, $to, $interval];
    }

    private function authorizeSite(Request $request, Site $site): void
    {
        abort_unless($site->user_id === $request->user()->id, 403);
    }
}
