<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Run at deploy time: refuses a database setup that would silently disable tenant isolation. */
final class TenancyCheckCommand extends Command
{
    protected $signature = 'tenancy:check';

    protected $description = 'Verify the DB role cannot bypass RLS and that RLS is forced on tenant tables';

    private const TENANT_TABLES = ['tenant_settings', 'invitations', 'audit_logs'];

    public function handle(): int
    {
        $problems = [];

        $role = DB::selectOne('select current_user as name, rolsuper, rolbypassrls from pg_roles where rolname = current_user');
        if ($role->rolsuper || $role->rolbypassrls) {
            $problems[] = "Database role '{$role->name}' is superuser or BYPASSRLS; RLS would not apply.";
        }

        foreach (self::TENANT_TABLES as $table) {
            $row = DB::selectOne(
                'select relrowsecurity as enabled, relforcerowsecurity as forced from pg_class where oid = to_regclass(?)',
                [$table],
            );
            if ($row === null || ! $row->enabled || ! $row->forced) {
                $problems[] = "RLS is not enabled and forced on '$table'.";
            }
        }

        foreach ($problems as $problem) {
            $this->error($problem);
        }
        if ($problems === []) {
            $this->info('Tenancy checks passed.');
        }

        return $problems === [] ? self::SUCCESS : self::FAILURE;
    }
}
