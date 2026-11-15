<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\ProcessLeadItem;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Websites stuck in "running" (worker killed) go back to the queue; after 3 attempts they are marked failed. */
final class LeadsRequeueStaleCommand extends Command
{
    protected $signature = 'leads:requeue-stale';

    protected $description = 'Re-queue lead research items whose worker died';

    public function handle(): int
    {
        $n = (int) DB::connection('admin')->scalar('select lead_items_requeue_stale(900)');
        $this->info("Re-queued/failed {$n} stale item(s).");
        if ($n > 0) {
            // The function runs without a tenant, so look the queued ones up with the bypass flag the admin connection uses.
            foreach (DB::connection('admin')->table('lead_research_items')->where('status', 'queued')->where('started_at', '<', now()->subMinutes(15))->limit(200)->get(['id', 'tenant_id']) as $i) {
                ProcessLeadItem::dispatch(trim($i->tenant_id), trim($i->id));
            }
        }

        return self::SUCCESS;
    }
}
