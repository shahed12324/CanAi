<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Re-adds the ledger and compares it with wallets, lots, reservations and usage records.
 * Any difference means money state was corrupted: it is logged, written to the audit log and the command FAILS
 * (so a scheduler/monitor notices). Uses the admin database login; credit_reconcile() is admin-only.
 */
final class CreditsReconcileCommand extends Command
{
    protected $signature = 'credits:reconcile {--tenant=}';

    protected $description = 'Verify wallets against the credit ledger';

    public function handle(): int
    {
        $tenant = $this->option('tenant') ?: null;
        $admin = DB::connection('admin');

        $problems = $admin->transaction(function () use ($admin, $tenant): array {
            $admin->select("select set_config('app.bypass_rls', 'on', true)");
            $rows = $admin->select('select tenant_id, check_name, expected, actual from credit_reconcile(?::char(26))', [$tenant]);
            foreach ($rows as $r) {
                $admin->table('audit_logs')->insert([
                    'tenant_id' => $r->tenant_id, 'actor_type' => 'system', 'action' => 'credits.reconcile.mismatch',
                    'subject_type' => 'wallet', 'subject_id' => $r->tenant_id,
                    'after' => json_encode(['check' => $r->check_name, 'expected' => $r->expected, 'actual' => $r->actual]),
                    'channel' => 'cli',
                ]);
            }

            return $rows;
        });

        if ($problems === []) {
            $this->info('Credit ledger is consistent.');

            return self::SUCCESS;
        }
        foreach ($problems as $r) {
            $this->error("tenant {$r->tenant_id}: {$r->check_name} expected {$r->expected}, actual {$r->actual}");
            Log::critical('credit reconciliation mismatch', ['tenant_id' => $r->tenant_id, 'check' => $r->check_name, 'expected' => $r->expected, 'actual' => $r->actual]);
        }

        return self::FAILURE;
    }
}
