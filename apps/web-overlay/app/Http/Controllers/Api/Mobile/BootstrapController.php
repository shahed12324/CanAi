<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Mobile;

use App\Mobile\MobileRepo;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantRoles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** First call after login: who am I, which workspaces, what may I do in the chosen one. */
final class BootstrapController extends MobileController
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        $workspaces = DB::table('tenant_users as tu')->join('tenants as t', 't.id', '=', 'tu.tenant_id')
            ->where('tu.user_id', $user->getAuthIdentifier())->where('tu.status', 'active')->where('t.status', 'active')->whereNull('t.deleted_at')
            ->orderByRaw("t.type = 'personal' desc")->orderBy('t.name')->get(['t.id', 't.name', 't.type'])
            ->map(fn ($w) => ['id' => trim($w->id), 'name' => $w->name, 'type' => $w->type])->all();

        $can = null;
        $tenantId = $request->header('X-Tenant-Id');
        if (is_string($tenantId) && TenantContext::isValidId($tenantId) && collect($workspaces)->contains('id', $tenantId)) {
            $can = $this->tenant->run($tenantId, fn () => TenantRoles::inTeam($tenantId, fn () => [
                'approve' => $user->can('outreach.approve'), 'outreach' => $user->can('outreach.view'),
                'emergency' => $user->can('emergency.control'), 'health' => $user->can('workspace.manage'),
                'billing' => $user->can('billing.view'),
            ]));
        }

        return response()->json(['data' => [
            'user' => ['id' => $user->getAuthIdentifier(), 'name' => $user->name, 'email' => $user->email, 'locale' => $user->locale],
            'workspaces' => $workspaces, 'can' => $can,
            'api_version' => 1, 'min_app_version' => config('mobile.min_app_version'),
        ]]);
    }
}
