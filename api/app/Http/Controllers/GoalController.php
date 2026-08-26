<?php

namespace App\Http\Controllers;

use App\Models\Goal;
use App\Models\Site;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GoalController extends Controller
{
    public function store(Request $request, Site $site): JsonResponse
    {
        $this->authorizeSite($request, $site);
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'event' => 'nullable|string|max:64|required_without:path_pattern',
            'path_pattern' => 'nullable|string|max:512|required_without:event',
        ]);

        return response()->json($site->goals()->create($data), 201);
    }

    public function update(Request $request, Site $site, Goal $goal): JsonResponse
    {
        $this->authorizeSite($request, $site);
        abort_unless($goal->site_id === $site->id, 404);
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'event' => 'nullable|string|max:64|required_without:path_pattern',
            'path_pattern' => 'nullable|string|max:512|required_without:event',
        ]);
        // switching type clears the other target column
        $goal->update(['name' => $data['name'], 'event' => $data['event'] ?? null, 'path_pattern' => $data['path_pattern'] ?? null]);

        return response()->json(['ok' => true]);
    }

    public function destroy(Request $request, Site $site, Goal $goal): JsonResponse
    {
        $this->authorizeSite($request, $site);
        abort_unless($goal->site_id === $site->id, 404);
        $goal->delete();

        return response()->json(['ok' => true]);
    }

    private function authorizeSite(Request $request, Site $site): void
    {
        abort_unless($site->user_id === $request->user()->id, 403);
    }
}
