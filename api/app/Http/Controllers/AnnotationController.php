<?php

namespace App\Http\Controllers;

use App\Models\Annotation;
use App\Models\Site;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AnnotationController extends Controller
{
    public function index(Request $request, Site $site): JsonResponse
    {
        $this->authorizeSite($request, $site);
        $q = $site->annotations()->orderBy('day');
        if ($from = $request->query('from')) {
            $q->where('day', '>=', $from);
        }
        if ($to = $request->query('to')) {
            $q->where('day', '<=', $to);
        }

        return response()->json(['annotations' => $q->get(['id', 'day', 'text'])]);
    }

    public function store(Request $request, Site $site): JsonResponse
    {
        $this->authorizeSite($request, $site);
        $data = $request->validate([
            'day' => 'required|date_format:Y-m-d',
            'text' => 'required|string|max:255',
        ]);

        return response()->json($site->annotations()->create($data), 201);
    }

    public function destroy(Request $request, Site $site, Annotation $annotation): JsonResponse
    {
        $this->authorizeSite($request, $site);
        abort_unless($annotation->site_id === $site->id, 404);
        $annotation->delete();

        return response()->json(['ok' => true]);
    }

    private function authorizeSite(Request $request, Site $site): void
    {
        abort_unless($site->user_id === $request->user()->id, 403);
    }
}
