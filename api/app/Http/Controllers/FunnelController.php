<?php

namespace App\Http\Controllers;

use App\Models\Funnel;
use App\Models\Site;
use App\Services\Stats;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FunnelController extends Controller
{
    public function __construct(private Stats $stats) {}

    public function index(Request $request, Site $site): JsonResponse
    {
        $this->authorizeSite($request, $site);
        [$from, $to] = $this->stats->range($request->query('from'), $request->query('to'), null, $site->timezone);

        return response()->json([
            'funnels' => $site->funnels->map(fn (Funnel $f) => [
                'id' => $f->id,
                'name' => $f->name,
                'definition' => $f->steps, // raw step config, for editing
                'steps' => $this->stats->funnel($site, $f->steps, $from, $to),
            ])->all(),
        ]);
    }

    public function store(Request $request, Site $site): JsonResponse
    {
        $this->authorizeSite($request, $site);
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'steps' => 'required|array|min:2|max:8',
            'steps.*.name' => 'nullable|string|max:255',
            'steps.*.event' => 'nullable|string|max:64|required_without:steps.*.path_pattern',
            'steps.*.path_pattern' => 'nullable|string|max:512|required_without:steps.*.event',
        ]);

        return response()->json($site->funnels()->create($data), 201);
    }

    public function update(Request $request, Site $site, Funnel $funnel): JsonResponse
    {
        $this->authorizeSite($request, $site);
        abort_unless($funnel->site_id === $site->id, 404);
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'steps' => 'required|array|min:2|max:8',
            'steps.*.name' => 'nullable|string|max:255',
            'steps.*.event' => 'nullable|string|max:64|required_without:steps.*.path_pattern',
            'steps.*.path_pattern' => 'nullable|string|max:512|required_without:steps.*.event',
        ]);
        $funnel->update($data);

        return response()->json(['ok' => true]);
    }

    public function destroy(Request $request, Site $site, Funnel $funnel): JsonResponse
    {
        $this->authorizeSite($request, $site);
        abort_unless($funnel->site_id === $site->id, 404);
        $funnel->delete();

        return response()->json(['ok' => true]);
    }

    private function authorizeSite(Request $request, Site $site): void
    {
        abort_unless($site->user_id === $request->user()->id, 403);
    }
}
