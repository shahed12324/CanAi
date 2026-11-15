<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\GoogleAds\GadsPauser;
use Illuminate\Console\Command;

/** Every minute: pauses the Google Ads campaigns that a person asked to pause. */
final class GoogleAdsPauseCommand extends Command
{
    protected $signature = 'gads:pause {--limit=3}';

    protected $description = 'Pause Google Ads campaigns people asked to pause';

    public function handle(GadsPauser $p): int
    {
        $r = $p->run(max(1, min(10, (int) $this->option('limit'))));
        if ($r['looked'] > 0) {
            $this->line("looked {$r['looked']}, paused {$r['paused']}");
        }

        return self::SUCCESS;
    }
}
