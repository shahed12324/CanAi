<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Writes off credit lots whose expiry date has passed (an `expiry` ledger entry each). */
final class CreditsExpireCommand extends Command
{
    protected $signature = 'credits:expire';

    protected $description = 'Expire credit lots past their expiry date';

    public function handle(): int
    {
        $n = (int) DB::connection('admin')->scalar('select credit_expire_lots()');
        $this->info("Expired {$n} credit lot(s).");

        return self::SUCCESS;
    }
}
