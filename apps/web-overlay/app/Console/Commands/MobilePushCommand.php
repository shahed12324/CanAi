<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Mobile\PushDispatcher;
use Illuminate\Console\Command;

/** Every minute: note "approvals waiting" for people who have the app, then push anything not pushed yet. */
final class MobilePushCommand extends Command
{
    protected $signature = 'mobile:push';

    protected $description = 'Create approval digests and send pending push notifications';

    public function handle(PushDispatcher $d): int
    {
        $d->retireDeadDevices();                       // before anything is sent
        $made = $d->digestApprovals();
        $r = $d->flush();
        if ($made + $r['sent'] + $r['failed'] > 0) {
            $this->line("digests {$made}, pushed {$r['sent']}, failed {$r['failed']}");
        }

        return self::SUCCESS;
    }
}
