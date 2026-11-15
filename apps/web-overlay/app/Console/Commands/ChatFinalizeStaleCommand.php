<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Marks answers whose stream never finished (killed PHP worker) as interrupted. Their credit hold is returned by credits:release-stale. */
final class ChatFinalizeStaleCommand extends Command
{
    protected $signature = 'chat:finalize-stale';

    protected $description = 'Close chat answers stuck in the streaming state';

    public function handle(): int
    {
        $n = (int) DB::connection('admin')->scalar('select chat_finalize_stale(1200)');
        $this->info("Closed {$n} interrupted answer(s).");

        return self::SUCCESS;
    }
}
