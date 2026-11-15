<?php

declare(strict_types=1);

namespace App\Tenancy;

use App\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Per-tenant roles (spatie teams mode; team id = tenant id).
 * Platform roles (Phase 3) use team_id NULL on `roles` and PLATFORM_TEAM on the pivot.
 */
final class TenantRoles
{
    public const PLATFORM_TEAM = '00000000000000000000000000';

    /** Roles that may be handed out by invitation (never Owner). */
    public const INVITABLE = ['Admin', 'Manager', 'Member', 'Auditor'];

    public const PERMISSIONS = [
        'workspace.manage',
        'members.view',
        'members.invite',
        'members.remove',
        'billing.view',
        'billing.manage',
        'audit.view',
        'leads.view',
        'leads.manage',
        'proposals.view',
        'proposals.manage',
        'outreach.view',
        'outreach.manage',
        'outreach.approve',
        'emergency.control',
        'catalog.view',
        'catalog.manage',
        'catalog.approve',
        'stores.manage',
        'inbox.view',
        'inbox.reply',
        'inbox.manage',
        'orders.view',
        'orders.manage',
        'orders.settings',
        'couriers.manage',
        'accounting.view',
        'accounting.manage',
        'accounting.settings',
        'accounting.audit',
        'payroll.view',
        'payroll.manage',
        'memory.manage',
        'knowledge.manage',
        'social.view',
        'social.manage',
        'social.approve',
        'social.connect',
        'ads.view',
        'ads.connect',
        'ads.manage',
        'ads.approve',
    ];

    public const MATRIX = [
        'Owner' => self::PERMISSIONS,
        'Admin' => ['members.view', 'members.invite', 'members.remove', 'billing.view', 'billing.manage', 'audit.view', 'leads.view', 'leads.manage', 'proposals.view', 'proposals.manage', 'outreach.view', 'outreach.manage', 'outreach.approve', 'emergency.control', 'catalog.view', 'catalog.manage', 'catalog.approve', 'stores.manage', 'inbox.view', 'inbox.reply', 'inbox.manage', 'orders.view', 'orders.manage', 'orders.settings', 'couriers.manage', 'accounting.view', 'accounting.manage', 'accounting.settings', 'accounting.audit', 'payroll.view', 'payroll.manage', 'memory.manage', 'knowledge.manage', 'social.view', 'social.manage', 'social.approve', 'social.connect', 'ads.view', 'ads.connect', 'ads.manage', 'ads.approve'],
        'Manager' => ['accounting.view', 'orders.view', 'orders.manage', 'inbox.view', 'inbox.reply', 'members.view', 'leads.view', 'leads.manage', 'proposals.view', 'proposals.manage', 'outreach.view', 'outreach.manage', 'outreach.approve', 'catalog.view', 'catalog.manage', 'catalog.approve', 'social.view', 'social.manage', 'social.approve', 'ads.view', 'ads.manage'],
        'Member' => ['orders.view', 'inbox.view', 'members.view', 'leads.view', 'proposals.view', 'outreach.view', 'catalog.view', 'social.view'],
        'Auditor' => ['accounting.view', 'accounting.audit', 'payroll.view', 'orders.view', 'inbox.view', 'members.view', 'billing.view', 'audit.view', 'leads.view', 'proposals.view', 'outreach.view', 'catalog.view', 'social.view', 'ads.view'],
    ];

    /** Creates the standard roles for a tenant. Idempotent. */
    public static function provision(string $tenantId): void
    {
        self::inTeam($tenantId, function (): void {
            foreach (self::PERMISSIONS as $permission) {
                Permission::findOrCreate($permission, 'web');
            }
            foreach (self::MATRIX as $name => $permissions) {
                Role::findOrCreate($name, 'web')->syncPermissions($permissions);
            }
        });
    }

    /** Gives $user exactly one role in the tenant. */
    public static function assign(User $user, string $tenantId, string $role): void
    {
        self::provision($tenantId);
        self::inTeam($tenantId, function () use ($user, $role): void {
            $user->unsetRelation('roles')->unsetRelation('permissions');
            $user->syncRoles([$role]);
        });
    }

    public static function revoke(User $user, string $tenantId): void
    {
        self::inTeam($tenantId, function () use ($user): void {
            $user->unsetRelation('roles')->unsetRelation('permissions');
            $user->syncRoles([]);
        });
    }

    /** @param  callable(): mixed  $callback */
    public static function inTeam(string $tenantId, callable $callback): mixed
    {
        $registrar = app(PermissionRegistrar::class);
        $previous = $registrar->getPermissionsTeamId();
        $registrar->setPermissionsTeamId($tenantId);
        try {
            return $callback();
        } finally {
            $registrar->setPermissionsTeamId($previous);
            $registrar->forgetCachedPermissions();
        }
    }
}
