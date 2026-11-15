<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Ads\AdsPauser;
use Illuminate\Console\Command;

/** Every minute: pauses the Meta campaigns that a person asked to pause. */
final class AdsPauseCommand extends Command
{
    protected $signature = 'ads:pause {--limit=3}';

    protected $description = 'Pause Meta campaigns people asked to pause';

    public function handle(AdsPauser $p): int
    {
        $r = $p->run(max(1, min(10, (int) $this->option('limit'))));
        if ($r['looked'] > 0) {
            $this->line("looked {$r['looked']}, paused {$r['paused']}");
        }

        return self::SUCCESS;
    }
}
