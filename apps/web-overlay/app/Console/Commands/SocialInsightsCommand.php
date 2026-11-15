<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Social\SocialInsights;
use Illuminate\Console\Command;

/** Every 15 minutes: refreshes the numbers (reactions, comments, shares, reach) of posts published to Facebook by the system. */
final class SocialInsightsCommand extends Command
{
    protected $signature = 'social:insights {--limit=10}';

    protected $description = 'Refresh Facebook post insights';

    public function handle(SocialInsights $insights): int
    {
        $r = $insights->run(max(1, min(20, (int) $this->option('limit'))));
        if ($r['looked'] > 0) {
            $this->line("looked {$r['looked']}, saved {$r['saved']}");
        }

        return self::SUCCESS;
    }
}
