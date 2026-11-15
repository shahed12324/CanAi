<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Ads\MetaAdsSync;
use Illuminate\Console\Command;

/** Every 30 minutes: copies campaigns and daily results of connected Meta ad accounts (read only; each account at most once per hour). */
final class AdsSyncCommand extends Command
{
    protected $signature = 'ads:sync {--limit=3}';

    protected $description = 'Sync connected Meta ad accounts (read only)';

    public function handle(MetaAdsSync $sync): int
    {
        $r = $sync->run(max(1, min(10, (int) $this->option('limit'))));
        if ($r['looked'] > 0) {
            $this->line("looked {$r['looked']}, saved {$r['saved']}");
        }

        return self::SUCCESS;
    }
}
