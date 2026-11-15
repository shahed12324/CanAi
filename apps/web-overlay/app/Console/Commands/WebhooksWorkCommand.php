<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Developer\Webhooks;
use Illuminate\Console\Command;

/** Every minute: queue "run finished" messages and send the due ones. */
final class WebhooksWorkCommand extends Command
{
    protected $signature = 'webhooks:work {--limit=20}';

    protected $description = 'Send due webhook messages';

    public function handle(Webhooks $webhooks): int
    {
        $r = $webhooks->work(max(1, min(100, (int) $this->option('limit'))));
        if ($r['queued'] + $r['sent'] + $r['failed'] > 0) {
            $this->line("queued {$r['queued']}, sent {$r['sent']}, failed {$r['failed']}");
        }

        return self::SUCCESS;
    }
}
