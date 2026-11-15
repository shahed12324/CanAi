<?php

namespace Database\Seeders;

use App\Admin\PlatformRoles;
use App\Tenancy\TenantRoles;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;

/** Safe for every environment; idempotent. Per-tenant roles are created with each workspace. */
class PlatformSeeder extends Seeder
{
    public function run(): void
    {
        foreach (TenantRoles::PERMISSIONS as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        PlatformRoles::provision();
    }
}
