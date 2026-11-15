<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Public;

use App\Credits\CreditService;
use App\Developer\Mcp\LaravelMcpBackend;
use App\Developer\Mcp\McpServer;
use App\Http\Controllers\Controller;
use App\Tenancy\TenantContext;
use App\Workflow\WorkflowRepo;
use App\Workflow\WorkflowService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** `POST /api/public/v1/mcp` - one JSON-RPC message per request (MCP "streamable HTTP", JSON replies only). Any valid key may connect; each tool still needs its own scope. */
final class McpController extends Controller
{
    public function handle(Request $request, TenantContext $tenant, WorkflowRepo $workflows, WorkflowService $service, CreditService $credits): Response
    {
        $raw = (string) $request->getContent();
        if (strlen($raw) > McpServer::MAX_BODY) {
            return response()->json(['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32600, 'message' => 'Request too large']], 413);
        }
        $msg = json_decode($raw, true, 16);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return response()->json(['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32700, 'message' => 'Parse error']], 400);
        }
        $backend = new LaravelMcpBackend($tenant->idOrFail(), (string) $request->attributes->get('api_user'), $workflows, $service, $credits);
        $out = (new McpServer($backend, (array) $request->attributes->get('api_scopes', [])))->handle($msg);

        return $out === null ? response('', 202) : response()->json($out);
    }

    public function get(): Response
    {
        return response('', 405, ['Allow' => 'POST']);              // no server-to-client stream
    }
}
