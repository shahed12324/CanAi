<?php

declare(strict_types=1);

namespace App\Admin;

use App\Models\User;
use App\Tenancy\TenantRoles;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/** Platform (admin panel) roles. They live in the "platform team" TenantRoles::PLATFORM_TEAM. */
final class PlatformRoles
{
    public const SUPER_ADMIN = 'Super Admin';

    public const PERMISSIONS = [
        'admin.access',
        'users.view', 'users.manage',
        'tenants.view', 'tenants.manage',
        'roles.manage',
        'plans.manage', 'features.manage', 'flags.manage',
        'plugins.review',
        'resellers.manage',
        'brands.manage',
        'licenses.manage',
        'settings.manage',
        'providers.manage',
        'credits.manage',
        'billing.manage',
        'audit.view',
    ];

    /** Super Admin always has every permission (see provision()). */
    public const MATRIX = [
        self::SUPER_ADMIN => self::PERMISSIONS,
        'System Admin' => ['admin.access', 'users.view', 'tenants.view', 'features.manage', 'flags.manage', 'plugins.review', 'resellers.manage', 'brands.manage', 'licenses.manage', 'settings.manage', 'audit.view'],
        'Support Admin' => ['admin.access', 'users.view', 'users.manage', 'tenants.view', 'audit.view'],
        'Finance Admin' => ['admin.access', 'users.view', 'tenants.view', 'plans.manage', 'credits.manage', 'billing.manage', 'audit.view'],
        'Provider Manager' => ['admin.access', 'providers.manage', 'audit.view'],
        'Read-Only Auditor' => ['admin.access', 'users.view', 'tenants.view', 'audit.view'],
    ];

    public static function names(): array
    {
        return array_keys(self::MATRIX);
    }

    /** Creates roles that do not exist yet; never overwrites permissions an admin has customised. */
    public static function provision(): void
    {
        TenantRoles::inTeam(TenantRoles::PLATFORM_TEAM, function (): void {
            foreach (self::PERMISSIONS as $permission) {
                Permission::findOrCreate($permission, 'web');
            }
            foreach (self::MATRIX as $name => $permissions) {
                $role = Role::findOrCreate($name, 'web');
                if ($name === self::SUPER_ADMIN || $role->wasRecentlyCreated) {
                    $role->syncPermissions($permissions);
                }
            }
        });
    }

    public static function assign(User $user, string $role): void
    {
        self::provision();
        TenantRoles::inTeam(TenantRoles::PLATFORM_TEAM, function () use ($user, $role): void {
            $user->unsetRelation('roles')->unsetRelation('permissions');
            $user->syncRoles([$role]);
        });
    }

    public static function revoke(User $user): void
    {
        TenantRoles::inTeam(TenantRoles::PLATFORM_TEAM, function () use ($user): void {
            $user->unsetRelation('roles')->unsetRelation('permissions');
            $user->syncRoles([]);
        });
    }

    public static function roleOf(string $userId): ?string
    {
        return DB::table('model_has_roles as mr')
            ->join('roles as r', 'r.id', '=', 'mr.role_id')
            ->where('mr.team_id', TenantRoles::PLATFORM_TEAM)
            ->where('mr.model_type', (new User)->getMorphClass())
            ->where('mr.model_id', $userId)
            ->value('r.name');
    }

    public static function superAdminCount(): int
    {
        return DB::table('model_has_roles as mr')
            ->join('roles as r', 'r.id', '=', 'mr.role_id')
            ->join('users as u', 'u.id', '=', 'mr.model_id')
            ->where('mr.team_id', TenantRoles::PLATFORM_TEAM)
            ->where('mr.model_type', (new User)->getMorphClass())
            ->where('r.name', self::SUPER_ADMIN)
            ->where('u.is_platform_admin', true)->where('u.status', 'active')->whereNull('u.deleted_at')
            ->count();
    }
}
