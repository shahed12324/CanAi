<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\GoogleAds\GoogleAdsSync;
use Illuminate\Console\Command;

/** Every 30 minutes: copies campaigns and daily results of connected Google Ads accounts (read only; each account at most once per hour). */
final class GoogleAdsSyncCommand extends Command
{
    protected $signature = 'gads:sync {--limit=3}';

    protected $description = 'Sync connected Google Ads accounts (read only)';

    public function handle(GoogleAdsSync $sync): int
    {
        $r = $sync->run(max(1, min(10, (int) $this->option('limit'))));
        if ($r['looked'] > 0) {
            $this->line("looked {$r['looked']}, saved {$r['saved']}");
        }

        return self::SUCCESS;
    }
}
