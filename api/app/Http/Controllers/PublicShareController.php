<?php

namespace App\Http\Controllers;

use App\Models\ShareLink;
use App\Services\Stats;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class PublicShareController extends Controller
{
    public function __construct(private Stats $stats) {}

    /** Public metadata: does this link exist / need a password? */
    public function meta(string $token): JsonResponse
    {
        $link = $this->link($token);

        return response()->json([
            'site' => $link->site->only(['name', 'domain']),
            'requires_password' => $link->has_password,
        ]);
    }

    public function unlock(Request $request, string $token): JsonResponse
    {
        $link = $this->link($token);
        $password = $request->validate(['password' => 'required|string'])['password'];
        if (! $link->password_hash || ! Hash::check($password, $link->password_hash)) {
            abort(403, 'Wrong password');
        }

        return response()->json(['auth' => $this->authToken($link)]);
    }

    public function stats(Request $request, string $token): JsonResponse
    {
        $link = $this->authorize($request, $token);
        [$from, $to, $interval] = $this->stats->range($request->query('from'), $request->query('to'), $request->query('interval'), $link->site->timezone);

        return response()->json(
            $this->stats->overview($link->site, $from, $to, $interval)
            + ['site' => $link->site->only(['name', 'domain'])]
        );
    }

    public function breakdown(Request $request, string $token): JsonResponse
    {
        $link = $this->authorize($request, $token);
        // public shares expose only the column-backed dimensions, not session/event ones
        $dimension = $request->validate([
            'dimension' => ['required', \Illuminate\Validation\Rule::in(array_keys(Stats::FILTERABLE))],
        ])['dimension'];
        [$from, $to] = $this->stats->range($request->query('from'), $request->query('to'), null, $link->site->timezone);

        return response()->json([
            'dimension' => $dimension,
            'rows' => $this->stats->breakdown($link->site, $dimension, $from, $to, min((int) $request->query('limit', 20), 100)),
        ]);
    }

    private function link(string $token): ShareLink
    {
        return ShareLink::where('token', $token)->where('enabled', true)->firstOrFail();
    }

    private function authorize(Request $request, string $token): ShareLink
    {
        $link = $this->link($token);
        if ($link->password_hash && ! hash_equals($this->authToken($link), (string) $request->query('auth'))) {
            abort(401, 'Password required');
        }

        return $link;
    }

    /** Stateless unlock token; rotates when the password changes. */
    private function authToken(ShareLink $link): string
    {
        return hash_hmac('sha256', $link->token.'|'.$link->password_hash, config('app.key'));
    }
}
