<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Gives back credits held by requests that never settled (crashed worker, timeout). Safe to run any time. */
final class CreditsReleaseStaleCommand extends Command
{
    protected $signature = 'credits:release-stale';

    protected $description = 'Release expired credit reservations';

    public function handle(): int
    {
        $n = (int) DB::connection('admin')->scalar('select credit_release_stale()');
        $this->info("Released {$n} stale reservation(s).");

        return self::SUCCESS;
    }
}
