<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class ChannelsPurgeEventsCommand extends Command
{
    protected $signature = 'channels:purge-events {--days=30}';

    protected $description = 'Delete old finished gateway events (metadata only)';

    public function handle(): int
    {
        $n = (int) DB::connection('admin')->scalar('select channel_events_purge(?)', [max(7, (int) $this->option('days'))]);
        $this->info("Deleted {$n} old event(s).");

        return self::SUCCESS;
    }
}
