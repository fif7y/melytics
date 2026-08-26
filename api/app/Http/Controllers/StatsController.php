<?php

namespace App\Http\Controllers;

use App\Models\Site;
use App\Services\Stats;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StatsController extends Controller
{
    public function __construct(private Stats $stats) {}

    public function stats(Request $request, Site $site): JsonResponse
    {
        $this->authorizeSite($request, $site);
        [$from, $to, $interval] = $this->stats->range($request->query('from'), $request->query('to'), $request->query('interval'), $site->timezone);

        return response()->json($this->stats->overview($site, $from, $to, $interval, $this->filterParam($request)));
    }

    /** Parse an optional "dimension:value" cross-filter query param. */
    private function filterParam(Request $request): ?array
    {
        $raw = $request->query('filter');
        if (! is_string($raw) || ! str_contains($raw, ':')) {
            return null;
        }
        [$dimension, $value] = explode(':', $raw, 2);
        abort_unless(array_key_exists($dimension, Stats::FILTERABLE) && $value !== '', 422);

        return ['dimension' => $dimension, 'value' => $value];
    }

    public function breakdown(Request $request, Site $site): JsonResponse
    {
        $this->authorizeSite($request, $site);
        $dimension = $request->validate([
            'dimension' => 'required|in:page,referrer,country,device,browser,os,utm_source,utm_medium,utm_campaign,event,entry_page,exit_page,outbound,download,not_found,channel',
        ])['dimension'];
        [$from, $to] = $this->stats->range($request->query('from'), $request->query('to'), null, $site->timezone);
        $limit = min((int) $request->query('limit', 20), 100);

        return response()->json([
            'dimension' => $dimension,
            'rows' => $this->stats->breakdown($site, $dimension, $from, $to, $limit, $this->filterParam($request)),
        ]);
    }

    public function goals(Request $request, Site $site): JsonResponse
    {
        $this->authorizeSite($request, $site);
        [$from, $to] = $this->stats->range($request->query('from'), $request->query('to'), null, $site->timezone);

        return response()->json(['goals' => $this->stats->goals($site, $from, $to)]);
    }

    public function vitals(Request $request, Site $site): JsonResponse
    {
        $this->authorizeSite($request, $site);
        [$from, $to] = $this->stats->range($request->query('from'), $request->query('to'), null, $site->timezone);

        return response()->json($this->stats->vitals($site, $from, $to));
    }

    public function retention(Request $request, Site $site): JsonResponse
    {
        $this->authorizeSite($request, $site);
        [$from, $to] = $this->stats->range($request->query('from'), $request->query('to'), null, $site->timezone);

        return response()->json($this->stats->retention($site, $from, $to));
    }

    public function cohorts(Request $request, Site $site): JsonResponse
    {
        $this->authorizeSite($request, $site);

        return response()->json(['cohorts' => $this->stats->cohorts($site)]);
    }

    public function loyalty(Request $request, Site $site): JsonResponse
    {
        $this->authorizeSite($request, $site);
        [$from, $to] = $this->stats->range($request->query('from'), $request->query('to'), null, $site->timezone);

        return response()->json($this->stats->loyalty($site, $from, $to));
    }

    public function attribution(Request $request, Site $site): JsonResponse
    {
        $this->authorizeSite($request, $site);
        [$from, $to] = $this->stats->range($request->query('from'), $request->query('to'), null, $site->timezone);

        return response()->json($this->stats->attribution($site, $from, $to));
    }

    public function timeToConvert(Request $request, Site $site): JsonResponse
    {
        $this->authorizeSite($request, $site);
        [$from, $to] = $this->stats->range($request->query('from'), $request->query('to'), null, $site->timezone);

        return response()->json($this->stats->timeToConvert($site, $from, $to));
    }

    public function live(Request $request, Site $site): JsonResponse
    {
        $this->authorizeSite($request, $site);

        $since = now()->subMinutes(5);
        $visitors = DB::table('hits')
            ->where('site_id', $site->id)
            ->where('created_at', '>=', $since)
            ->distinct()
            ->count('visitor_hash');
        // Each visitor counts only on the page of their latest hit (pageview or
        // heartbeat ping), so this matches the visitor count instead of listing
        // every page they crossed in the window.
        $pages = DB::table(
            DB::table('hits')
                ->where('site_id', $site->id)
                ->where('created_at', '>=', $since)
                ->where(fn ($q) => $q->whereNull('event')->orWhere('event', '__ping'))
                ->groupBy('visitor_hash')
                ->selectRaw('visitor_hash, path, MAX(id)'), // SQLite: bare columns follow the MAX row
            'latest'
        )
            ->groupBy('path')
            ->orderByRaw('COUNT(*) DESC')
            ->limit(10)
            ->selectRaw('path, COUNT(*) as visitors')
            ->get();

        return response()->json(['visitors' => $visitors, 'pages' => $pages]);
    }

    private function authorizeSite(Request $request, Site $site): void
    {
        abort_unless($site->user_id === $request->user()->id, 403);
    }
}
