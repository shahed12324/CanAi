<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Couriers\CourierRepo;
use App\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Stores the daily order report (Asia/Dhaka day) for every workspace that had order activity. Safe to re-run: the day is replaced. */
final class OrdersDailyReportCommand extends Command
{
    protected $signature = 'orders:daily-report {--day= : YYYY-MM-DD (default: yesterday, Dhaka time)}';

    protected $description = 'Build the daily order report';

    public function handle(CourierRepo $repo, TenantContext $tenant): int
    {
        $day = (string) ($this->option('day') ?: now('Asia/Dhaka')->subDay()->toDateString());
        if (preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $day) !== 1 || ! checkdate((int) substr($day, 5, 2), (int) substr($day, 8, 2), (int) substr($day, 0, 4))) {
            $this->error('bad day');

            return self::FAILURE;
        }
        $tenants = DB::connection('admin')->select("select distinct tenant_id from store_order_events where (created_at at time zone 'Asia/Dhaka')::date = ?::date", [$day]);
        $done = 0;
        foreach ($tenants as $t) {
            try {
                $tenant->run(trim($t->tenant_id), function () use ($repo, $t, $day) {
                    $repo->reportStore(trim($t->tenant_id), $day, $repo->reportCalc($day));
                });
                $done++;
            } catch (\Throwable $e) {
                report($e);
            }
        }
        $this->line("orders report $day: $done workspace(s)");

        return self::SUCCESS;
    }
}
