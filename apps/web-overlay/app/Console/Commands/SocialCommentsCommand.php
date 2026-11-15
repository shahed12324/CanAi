<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Social\SocialCommentSync;
use Illuminate\Console\Command;

/** Every 10 minutes: fetches new comments on posts the system published to Facebook.  Read-only. */
final class SocialCommentsCommand extends Command
{
    protected $signature = 'social:comments {--limit=10}';

    protected $description = 'Fetch new Facebook comments';

    public function handle(SocialCommentSync $sync): int
    {
        $r = $sync->run(max(1, min(20, (int) $this->option('limit'))));
        if ($r['posts'] > 0) {
            $this->line("posts {$r['posts']}, new comments {$r['new']}");
        }

        return self::SUCCESS;
    }
}
