<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Response;

/**
 * Picks the tenant for an authenticated request (X-Tenant-Id header for API clients,
 * session value for the web) and verifies the user is an active member of an active tenant
 * BEFORE the database context is set.
 */
final class ResolveTenant
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        abort_if($user === null, 401);

        $tenantId = $request->header('X-Tenant-Id');
        if ($tenantId === null && $request->hasSession()) {
            $tenantId = $request->session()->get('tenant_id');
        }
        abort_unless(is_string($tenantId) && TenantContext::isValidId($tenantId), 403, 'No active workspace.');

        $isMember = DB::table('tenant_users as tu')
            ->join('tenants as t', 't.id', '=', 'tu.tenant_id')
            ->where('tu.user_id', $user->getAuthIdentifier())
            ->where('tu.tenant_id', $tenantId)
            ->where('tu.status', 'active')
            ->where('t.status', 'active')
            ->whereNull('t.deleted_at')
            ->exists();
        abort_unless($isMember, 403, 'No access to this workspace.');

        $registrar = app(PermissionRegistrar::class);
        $this->context->set($tenantId);
        $registrar->setPermissionsTeamId($tenantId);
        try {
            return $next($request);
        } finally {
            $this->context->clear();
            $registrar->setPermissionsTeamId(null);
        }
    }
}
