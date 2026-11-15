<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Tenancy\TenantRoles;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate for the admin panel. Requirements, in order: platform-admin flag + active account,
 * confirmed 2FA, 30-minute idle limit, admin DB login configured, `admin.access` permission.
 * Only then is cross-tenant (RLS bypass) mode switched on, on the separate admin connection,
 * and always switched off again.
 */
final class AdminAccess
{
    private const IDLE_SECONDS = 1800;

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        abort_unless($user !== null && $user->is_platform_admin && $user->status === 'active', 403);

        if ($user->two_factor_confirmed_at === null) {
            return redirect()->route('security.show')
                ->with('status', 'To enter the admin panel, first turn on two-step verification (2FA).');
        }

        abort_if(blank(config('database.connections.admin.password')), 503, 'Admin database login is not configured.');

        $last = (int) $request->session()->get('admin.last_activity', 0);
        if ($last > 0 && time() - $last > self::IDLE_SECONDS) {
            $request->session()->forget(['admin.last_activity', 'auth.password_confirmed_at']);

            return redirect()->guest(route('password.confirm'));
        }
        $request->session()->put('admin.last_activity', time());

        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId(TenantRoles::PLATFORM_TEAM);
        $user->unsetRelation('roles')->unsetRelation('permissions');
        abort_unless($user->can('admin.access'), 403);

        $admin = DB::connection('admin');
        $admin->select("select set_config('app.bypass_rls', 'on', false)");
        try {
            return $next($request);
        } finally {
            $admin->select("select set_config('app.bypass_rls', '', false)");
            $registrar->setPermissionsTeamId(null);
        }
    }
}
