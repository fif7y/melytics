<?php

namespace App\Http\Controllers;

use App\Models\Site;
use App\Services\Stats;
use App\Services\Tier2Stats;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StatsController extends Controller
{
    public function __construct(private Stats $stats, private Tier2Stats $tier2) {}

    public function stats(Request $request, Site $site): JsonResponse
    {
        $this->authorizeSite($request, $site);
        $this->lazyRollup();
        [$from, $to, $interval] = $this->stats->range($request->query('from'), $request->query('to'), $request->query('interval'), $site->timezone);

        return response()->json($this->stats->overview($site, $from, $to, $interval, $this->filterParam($request)));
    }

    /**
     * One payload for a full dashboard load. Every module the SPA shows used to
     * be its own request — each one booting the framework, which dominates cost
     * on shared hosting — so a site switch was ~16 round trips; now it is one.
     * ?modules= gates the optional sections, ?panels= names the breakdown dims.
     */
    public function dashboard(Request $request, Site $site): JsonResponse
    {
        $this->authorizeSite($request, $site);
        $this->lazyRollup();
        [$from, $to, $interval] = $this->stats->range($request->query('from'), $request->query('to'), $request->query('interval'), $site->timezone);
        $filter = $this->filterParam($request);
        $modules = array_filter(explode(',', (string) $request->query('modules')));
        $panels = array_values(array_intersect(
            array_filter(explode(',', (string) $request->query('panels'))),
            Stats::breakdownDimensions()
        ));
        $limit = min((int) $request->query('limit', 8), 100);
        $on = fn (string $m) => in_array($m, $modules, true);

        $annotations = $site->annotations()->orderBy('day')
            ->where('day', '>=', $from->toDateString())
            ->where('day', '<=', $to->toDateString())
            ->get(['id', 'day', 'text']);

        return response()->json([
            'stats' => $this->stats->overview($site, $from, $to, $interval, $filter),
            'annotations' => $annotations,
            'goals' => $on('goals') ? $this->stats->goals($site, $from, $to) : null,
            'funnels' => $on('funnels') ? $site->funnels->map(fn ($f) => [
                'id' => $f->id,
                'name' => $f->name,
                'definition' => $f->steps,
                'steps' => $this->stats->funnel($site, $f->steps, $from, $to),
            ])->all() : null,
            'vitals' => $on('vitals') ? $this->stats->vitals($site, $from, $to) : null,
            'retention' => $on('retention') ? $this->tier2->retention($site, $from, $to) : null,
            'cohorts' => $on('cohorts') ? $this->tier2->cohorts($site) : null,
            'loyalty' => $on('loyalty') ? $this->tier2->loyalty($site, $from, $to) : null,
            'attribution' => $on('attribution') ? $this->tier2->attribution($site, $from, $to) : null,
            'ttc' => $on('ttc') ? $this->tier2->timeToConvert($site, $from, $to) : null,
            'breakdowns' => collect($panels)->mapWithKeys(fn (string $dim) => [
                $dim => $this->stats->breakdown($site, $dim, $from, $to, $limit, $filter),
            ]),
        ]);
    }

    // No cron? Self-heal: roll up the trailing hour when someone actually looks
    // at their stats and the data is stale. Lock guards concurrent dashboards;
    // cron (schedule:run) stays the real driver — it also powers alerts/digests.
    private function lazyRollup(): void
    {
        if (! \App\Support\RollupHeartbeat::dataStale(120)) {
            return;
        }
        $lock = cache()->lock('melytics-lazy-rollup', 120);
        if (! $lock->get()) {
            return;
        }
        try {
            \Illuminate\Support\Facades\Artisan::call('melytics:rollup', ['--hours' => 1, '--lazy' => true]);
        } catch (\Throwable $e) {
            report($e);
        } finally {
            $lock->release();
        }
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
            'dimension' => ['required', \Illuminate\Validation\Rule::in(Stats::breakdownDimensions())],
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

        return response()->json($this->tier2->retention($site, $from, $to));
    }

    public function cohorts(Request $request, Site $site): JsonResponse
    {
        $this->authorizeSite($request, $site);

        return response()->json(['cohorts' => $this->tier2->cohorts($site)]);
    }

    public function loyalty(Request $request, Site $site): JsonResponse
    {
        $this->authorizeSite($request, $site);
        [$from, $to] = $this->stats->range($request->query('from'), $request->query('to'), null, $site->timezone);

        return response()->json($this->tier2->loyalty($site, $from, $to));
    }

    public function attribution(Request $request, Site $site): JsonResponse
    {
        $this->authorizeSite($request, $site);
        [$from, $to] = $this->stats->range($request->query('from'), $request->query('to'), null, $site->timezone);

        return response()->json($this->tier2->attribution($site, $from, $to));
    }

    public function timeToConvert(Request $request, Site $site): JsonResponse
    {
        $this->authorizeSite($request, $site);
        [$from, $to] = $this->stats->range($request->query('from'), $request->query('to'), null, $site->timezone);

        return response()->json($this->tier2->timeToConvert($site, $from, $to));
    }

    /** Real targets for goal/funnel builders: the site's actual pages and custom events. */
    public function targets(Request $request, Site $site): JsonResponse
    {
        $this->authorizeSite($request, $site);
        $since = now()->subDays(90)->toDateString();
        $rows = fn (string $dim) => DB::table('rollup_daily')
            ->where('site_id', $site->id)
            ->where('dimension', $dim)
            ->where('day', '>=', $since)
            ->groupBy('value')
            ->orderByRaw('SUM(pageviews) DESC')
            ->limit(50)
            ->pluck('value');

        return response()->json([
            'pages' => $rows('page'),
            'events' => $rows('event')->reject(fn ($e) => str_starts_with($e, '__'))->values(),
        ]);
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
