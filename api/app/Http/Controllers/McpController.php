<?php

namespace App\Http\Controllers;

use App\Support\Version;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * MCP over Streamable HTTP, served by the app itself — no Node, no npm.
 * Clients that can send headers use `POST /api/mcp` with a Bearer token;
 * clients that can't (claude.ai custom connectors) put the token in the
 * path: `POST /api/mcp/{token}`. Tool calls are replayed as internal
 * sub-requests against the regular stats API, so auth, site ownership,
 * and validation behave exactly like the dashboard.
 */
class McpController extends Controller
{
    private const PROTOCOL_VERSIONS = ['2024-11-05', '2025-03-26', '2025-06-18'];

    /** name => [path template, extra query params allowed besides from/to] */
    private const TOOLS = [
        'list_sites' => ['/sites', []],
        'get_stats' => ['/sites/{site_id}/stats', []],
        'get_breakdown' => ['/sites/{site_id}/breakdown', ['dimension', 'limit']],
        'get_goals' => ['/sites/{site_id}/goals', []],
        'get_funnels' => ['/sites/{site_id}/funnels', []],
        'get_vitals' => ['/sites/{site_id}/vitals', []],
        'get_live' => ['/sites/{site_id}/live', []],
        'get_annotations' => ['/sites/{site_id}/annotations', []],
    ];

    public function handle(Request $request, ?string $token = null)
    {
        $plain = $token ?? (string) $request->bearerToken();
        // MCP-scoped tokens only — a leaked dashboard token must not drive MCP,
        // and regenerating the MCP token must actually cut off MCP access.
        // Legacy tokens minted before scoping carry ['*'], which `can` honors,
        // so existing connectors keep working until they are regenerated.
        $accessToken = $plain ? PersonalAccessToken::findToken($plain) : null;
        if (! $accessToken || ! $accessToken->can('mcp')) {
            return response()->json(['error' => 'invalid or missing token'], 401);
        }

        $msg = $request->json()->all();
        if (! isset($msg['jsonrpc'])) {
            return response()->json(['error' => 'expected a JSON-RPC 2.0 message'], 400);
        }

        $method = $msg['method'] ?? '';
        $id = $msg['id'] ?? null;

        // Notifications carry no id and expect no body.
        if (str_starts_with($method, 'notifications/')) {
            return response()->noContent(202);
        }

        $result = match ($method) {
            'initialize' => [
                'protocolVersion' => in_array($msg['params']['protocolVersion'] ?? '', self::PROTOCOL_VERSIONS)
                    ? $msg['params']['protocolVersion'] : '2025-03-26',
                'capabilities' => ['tools' => (object) []],
                'serverInfo' => ['name' => 'melytics', 'version' => Version::current()],
            ],
            'ping' => (object) [],
            'tools/list' => ['tools' => $this->toolList()],
            'tools/call' => $this->call($msg['params'] ?? [], $plain),
            default => null,
        };

        if ($result === null) {
            return response()->json([
                'jsonrpc' => '2.0', 'id' => $id,
                'error' => ['code' => -32601, 'message' => "Method not found: $method"],
            ]);
        }

        return response()->json(['jsonrpc' => '2.0', 'id' => $id, 'result' => $result]);
    }

    /** GET/DELETE on the endpoint — this server speaks single-response JSON only, no SSE stream. */
    public function reject(): Response
    {
        return response('', 405, ['Allow' => 'POST']);
    }

    private function toolList(): array
    {
        $siteId = ['type' => 'integer', 'description' => 'Site id (see list_sites)'];
        $range = [
            'site_id' => $siteId,
            'from' => ['type' => 'string', 'description' => 'Start date YYYY-MM-DD (default: 30 days ago)'],
            'to' => ['type' => 'string', 'description' => 'End date YYYY-MM-DD (default: today)'],
        ];
        $schema = fn (array $props, array $required = []) => [
            'type' => 'object', 'properties' => (object) $props, 'required' => $required,
        ];

        return [
            ['name' => 'list_sites', 'description' => 'List the sites tracked by this melytics instance', 'inputSchema' => $schema([])],
            ['name' => 'get_stats', 'description' => 'Traffic overview for a site: time series, totals, and previous-period comparison', 'inputSchema' => $schema($range, ['site_id'])],
            ['name' => 'get_breakdown', 'description' => 'Top values for one dimension (pages, referrers, countries, devices, browsers, OS, UTM, events)', 'inputSchema' => $schema($range + [
                'dimension' => ['type' => 'string', 'enum' => ['page', 'referrer', 'country', 'device', 'browser', 'os', 'utm_source', 'utm_medium', 'utm_campaign', 'event'], 'description' => 'Dimension to break down by'],
                'limit' => ['type' => 'integer', 'description' => 'Max rows (default 20, max 100)'],
            ], ['site_id', 'dimension'])],
            ['name' => 'get_goals', 'description' => 'Goal conversion counts and rates for a site', 'inputSchema' => $schema($range, ['site_id'])],
            ['name' => 'get_funnels', 'description' => 'Funnel step-by-step visitor counts and drop-off rates', 'inputSchema' => $schema($range, ['site_id'])],
            ['name' => 'get_vitals', 'description' => 'p75 Core Web Vitals (LCP, CLS, INP, TTFB) for a site', 'inputSchema' => $schema($range, ['site_id'])],
            ['name' => 'get_live', 'description' => 'Visitors online right now (last 5 minutes) and the pages they are on', 'inputSchema' => $schema(['site_id' => $siteId], ['site_id'])],
            ['name' => 'get_annotations', 'description' => 'Chart annotations (deploys, launches, campaigns) noted on the dashboard', 'inputSchema' => $schema($range, ['site_id'])],
        ];
    }

    private function call(array $params, string $plain): array
    {
        $name = $params['name'] ?? '';
        $args = $params['arguments'] ?? [];
        if (! isset(self::TOOLS[$name])) {
            return $this->error("Unknown tool: $name");
        }

        [$path, $extra] = self::TOOLS[$name];
        $path = str_replace('{site_id}', (string) (int) ($args['site_id'] ?? 0), $path);
        $query = array_filter(
            array_intersect_key($args, array_flip(array_merge(['from', 'to'], $extra))),
            fn ($v) => $v !== null && $v !== ''
        );

        $sub = Request::create('/api'.$path, 'GET', $query);
        $sub->headers->set('Accept', 'application/json');
        $sub->headers->set('Authorization', 'Bearer '.$plain);
        $response = app()->handle($sub);

        if ($response->getStatusCode() >= 400) {
            return $this->error("melytics API {$response->getStatusCode()} for $path: ".$response->getContent());
        }

        return ['content' => [['type' => 'text', 'text' => $response->getContent()]]];
    }

    private function error(string $message): array
    {
        return ['content' => [['type' => 'text', 'text' => $message]], 'isError' => true];
    }
}
