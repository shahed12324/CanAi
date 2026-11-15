<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Billing\PgBillingRepo;
use Illuminate\Console\Command;

/** Scheduler tick: expire stale orders, start the grace period, end grace/cancelled plans, give yearly plans their monthly credits. */
final class BillingRunCommand extends Command
{
    protected $signature = 'billing:run';

    protected $description = 'Subscription lifecycle: expiry, grace period, cancellation, monthly credit refill';

    public function handle(PgBillingRepo $repo): int
    {
        $r = $repo->lifecycle();
        $this->info(sprintf('orders expired %d, to grace %d, expired %d, cancelled %d, refills %d', $r['orders_expired'], $r['to_grace'], $r['subs_expired'], $r['subs_cancelled'], $r['refills']));

        return self::SUCCESS;
    }
}
