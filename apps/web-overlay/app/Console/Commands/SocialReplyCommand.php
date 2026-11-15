<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Social\SocialCommentReplier;
use Illuminate\Console\Command;

/** Every minute: sends the replies a person approved.  Nothing unapproved is ever sent. */
final class SocialReplyCommand extends Command
{
    protected $signature = 'social:reply {--limit=5}';

    protected $description = 'Send approved Facebook comment replies';

    public function handle(SocialCommentReplier $replier): int
    {
        $r = $replier->run(max(1, min(10, (int) $this->option('limit'))));
        if ($r['sent'] + $r['failed'] > 0) {
            $this->line("sent {$r['sent']}, failed {$r['failed']}");
        }

        return self::SUCCESS;
    }
}
