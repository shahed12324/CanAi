<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Developer\ApiKeys;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Logs a request in by its API key (`Authorization: Bearer jk_live_…`) and sets the workspace.  Usage: `ApiKeyAuth::class.':workflows:run'` (`:*` = any valid key; the route then checks scopes itself, as MCP does).
 * Answers are always JSON and never say WHY a key was refused (missing, wrong, revoked, expired and removed look the same).
 */
final class ApiKeyAuth
{
    public function __construct(private readonly ApiKeys $keys, private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next, string $scope): Response
    {
        $auth = $this->keys->authenticate($request->header('Authorization'));
        if ($auth === null) {
            return response()->json(['error' => 'unauthorized', 'message' => 'API key missing or not valid.'], 401, ['WWW-Authenticate' => 'Bearer']);
        }
        if ($scope !== '*' && ! ApiKeys::allows($auth, $scope)) {
            return response()->json(['error' => 'forbidden', 'message' => 'This key does not have the "'.$scope.'" scope.'], 403);
        }
        $request->attributes->set('api_key_id', $auth['key_id']);
        $request->attributes->set('api_user', $auth['user_id']);
        $request->attributes->set('api_tenant', $auth['tenant_id']);
        $request->attributes->set('api_scopes', $auth['scopes']);
        $this->context->set($auth['tenant_id']);
        try {
            return $next($request);
        } finally {
            $this->context->clear();
        }
    }
}
