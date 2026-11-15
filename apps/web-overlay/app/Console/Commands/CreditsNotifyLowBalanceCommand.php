<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Notifications\LowBalanceNotification;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Emails the workspace owner for each open low-balance alert, then marks it handled.
 * Alerts are created by the database exactly once per dip (re-armed when the balance recovers), so this only delivers.
 * A failed send leaves the alert open and it is retried on the next run.
 */
final class CreditsNotifyLowBalanceCommand extends Command
{
    protected $signature = 'credits:notify-low-balance {--limit=200}';

    protected $description = 'Send pending low-balance alerts to workspace owners';

    public function handle(): int
    {
        $admin = DB::connection('admin');
        $limit = max(1, min(1000, (int) $this->option('limit')));

        $alerts = $admin->transaction(function () use ($admin, $limit) {
            $admin->select("select set_config('app.bypass_rls', 'on', true)");

            return $admin->table('credit_alerts as a')
                ->join('tenants as t', 't.id', '=', 'a.tenant_id')
                ->whereNull('a.handled_at')->orderBy('a.id')->limit($limit)
                ->get(['a.id', 'a.available', 't.id as tenant_id', 't.name', 't.owner_id']);
        });

        $sent = 0;
        foreach ($alerts as $alert) {
            try {
                $owner = $alert->owner_id === null ? null : User::find($alert->owner_id);
                $owner?->notify(new LowBalanceNotification($alert->name, (string) $alert->available, url('/app/credits')));
                $sent += $owner === null ? 0 : 1;
                // No owner (or deleted workspace) -> nothing to deliver; close the alert so it does not pile up.
                $admin->transaction(function () use ($admin, $alert): void {
                    $admin->select("select set_config('app.bypass_rls', 'on', true)");
                    $admin->table('credit_alerts')->where('id', $alert->id)->update(['handled_at' => now()]);
                });
            } catch (\Throwable $e) {
                report($e);
                $this->warn("alert {$alert->id}: will retry");
            }
        }
        $this->info("Delivered {$sent} alert(s) of ".count($alerts).'.');

        return self::SUCCESS;
    }
}
