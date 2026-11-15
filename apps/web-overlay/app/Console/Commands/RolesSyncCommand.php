<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Tenancy\TenantRoles;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** After an update adds permissions (e.g. leads.*), give every existing workspace the new role matrix. Idempotent. */
final class RolesSyncCommand extends Command
{
    protected $signature = 'roles:sync';

    protected $description = 'Re-provision the standard roles/permissions for every workspace';

    public function handle(): int
    {
        $n = 0;
        foreach (DB::connection('admin')->table('tenants')->whereNull('deleted_at')->pluck('id') as $id) {
            TenantRoles::provision(trim((string) $id));
            $n++;
        }
        $this->info("Roles synced for {$n} workspace(s).");

        return self::SUCCESS;
    }
}
