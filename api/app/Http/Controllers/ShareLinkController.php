<?php

namespace App\Http\Controllers;

use App\Models\Site;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class ShareLinkController extends Controller
{
    /** Owner-side: read or lazily create the site's share link. */
    public function show(Request $request, Site $site): JsonResponse
    {
        $this->authorizeSite($request, $site);

        return response()->json($site->shareLink()->firstOrCreate([], ['enabled' => false]));
    }

    public function update(Request $request, Site $site): JsonResponse
    {
        $this->authorizeSite($request, $site);
        $data = $request->validate([
            'enabled' => 'sometimes|boolean',
            'password' => 'sometimes|nullable|string|min:6|max:255',
        ]);

        $link = $site->shareLink()->firstOrCreate([]);
        if (array_key_exists('password', $data)) {
            $link->password_hash = $data['password'] ? Hash::make($data['password']) : null;
        }
        if (array_key_exists('enabled', $data)) {
            $link->enabled = $data['enabled'];
        }
        $link->save();

        return response()->json($link->fresh());
    }

    private function authorizeSite(Request $request, Site $site): void
    {
        abort_unless($site->user_id === $request->user()->id, 403);
    }
}
