<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Pc\PcService;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Logs the desktop agent in by its device token (`Authorization: Bearer <64 hex>`) and sets the workspace. Refusals never say why. */
final class PcAgentAuth
{
    public function __construct(private readonly PcService $pc, private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $h = (string) $request->header('Authorization', '');
        $who = str_starts_with($h, 'Bearer ') ? $this->pc->authenticate(substr($h, 7)) : null;
        if ($who === null) {
            return response()->json(['error' => 'unauthorized'], 401, ['WWW-Authenticate' => 'Bearer']);
        }
        $request->attributes->set('pc_device', $who['device_id']);
        $request->attributes->set('pc_tenant', $who['tenant_id']);
        $request->attributes->set('pc_user', $who['user_id'] ?? '');
        $this->context->set($who['tenant_id']);
        try {
            return $next($request);
        } finally {
            $this->context->clear();
        }
    }
}
