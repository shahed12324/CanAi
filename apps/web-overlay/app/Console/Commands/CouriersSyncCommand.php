<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Couriers\ShipmentService;
use App\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Asks each courier where the open parcels are and moves the orders (delivered / returned). One bad parcel never stops the rest. */
final class CouriersSyncCommand extends Command
{
    protected $signature = 'couriers:sync';

    protected $description = 'Refresh courier status of open parcels';

    public function handle(ShipmentService $svc, TenantContext $tenant): int
    {
        $rows = DB::connection('admin')->select("select id, tenant_id from order_shipments where status in ('booked', 'in_transit', 'attention') and (last_checked_at is null or last_checked_at < now() - interval '15 minutes') order by last_checked_at nulls first limit ?",
            [max(1, (int) config('couriers.sync_batch', 100))]);
        $n = [];
        foreach ($rows as $r) {
            try {
                $res = $tenant->run(trim($r->tenant_id), fn () => $svc->sync(trim($r->id)));
            } catch (\Throwable $e) {
                $res = 'error';
                report($e);
            }
            $n[$res] = ($n[$res] ?? 0) + 1;
        }
        $this->line('couriers: '.($n === [] ? 'nothing to check' : implode(', ', array_map(fn ($k, $v) => "$k $v", array_keys($n), $n))));

        return self::SUCCESS;
    }
}
