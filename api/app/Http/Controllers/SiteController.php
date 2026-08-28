<?php

namespace App\Http\Controllers;

use App\Models\Site;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SiteController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json($request->user()->sites()->orderBy('name')->get());
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless((bool) $request->user()->email_verified_at, 403, 'Verify your email to add sites.');
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'domain' => 'required|string|max:255',
            'timezone' => 'sometimes|timezone',
        ]);

        return response()->json($request->user()->sites()->create($data), 201);
    }

    public function update(Request $request, Site $site): JsonResponse
    {
        abort_unless($site->user_id === $request->user()->id, 403);
        $data = $request->validate([
            'name' => 'sometimes|string|max:255',
            'domain' => 'sometimes|string|max:255',
            'timezone' => 'sometimes|timezone',
            'currency' => ['sometimes', 'nullable', 'string', 'regex:/^[A-Za-z]{3}$/'],
            'retention_days' => 'sometimes|integer|min:1|max:3650',
            'tier2_enabled' => 'sometimes|boolean',
            'digest_enabled' => 'sometimes|boolean',
            'alerts_enabled' => 'sometimes|boolean',
        ]);
        $site->update($data);

        return response()->json($site);
    }

    public function destroy(Request $request, Site $site): JsonResponse
    {
        abort_unless($site->user_id === $request->user()->id, 403);
        $site->delete();

        return response()->json(['ok' => true]);
    }
}
