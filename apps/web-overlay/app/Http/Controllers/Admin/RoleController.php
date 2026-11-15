<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Admin\AdminAudit;
use App\Admin\PlatformRoles;
use App\Tenancy\TenantRoles;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;

final class RoleController extends AdminController
{
    public function index()
    {
        $this->allow('roles.manage');

        $platform = [];
        foreach (PlatformRoles::names() as $name) {
            $platform[$name] = Role::findByName($name, 'web')->permissions->pluck('name')->all();
        }

        return view('admin.roles.index', [
            'platform' => $platform,
            'permissions' => PlatformRoles::PERMISSIONS,
            'tenantRoles' => TenantRoles::MATRIX,
            'tenantPermissions' => TenantRoles::PERMISSIONS,
        ]);
    }

    /** Super Admin is fixed at "everything"; other platform roles can be tuned. */
    public function update(Request $request, string $name)
    {
        $this->allow('roles.manage');
        abort_unless(in_array($name, PlatformRoles::names(), true), 404);
        abort_if($name === PlatformRoles::SUPER_ADMIN, 422, 'Super Admin-The permissions of cannot be changed.');
        $data = $request->validate([
            'permissions' => ['array'],
            'permissions.*' => ['string', Rule::in(PlatformRoles::PERMISSIONS)],
        ]);
        $new = array_values(array_unique($data['permissions'] ?? []));

        $role = Role::findByName($name, 'web');
        $before = $role->permissions->pluck('name')->sort()->values()->all();
        $role->syncPermissions($new);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $this->db()->transaction(fn () => AdminAudit::record('admin.role.permissions_changed', 'role', $name,
            ['permissions' => $before], ['permissions' => collect($new)->sort()->values()->all()]));

        return back()->with('status', 'The role’s permissions were updated.');
    }
}
