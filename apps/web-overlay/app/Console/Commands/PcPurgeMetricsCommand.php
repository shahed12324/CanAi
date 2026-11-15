<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Deletes system-monitor samples older than N days (default 7). Finished actions are kept: they are the audit trail. */
final class PcPurgeMetricsCommand extends Command
{
    protected $signature = 'pc:purge-metrics {--days=7}';

    protected $description = 'Delete old PC monitor samples';

    public function handle(): int
    {
        $n = (int) DB::connection('admin')->scalar('select pc_metrics_purge(?)', [max(1, (int) $this->option('days'))]);
        $this->info("{$n} sample(s) deleted.");

        return self::SUCCESS;
    }
}
