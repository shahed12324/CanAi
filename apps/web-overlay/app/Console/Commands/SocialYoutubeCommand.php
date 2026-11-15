<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Social\SocialPublishRepo;
use App\Social\YoutubePublisher;
use App\Tenancy\TenantContext;
use Illuminate\Console\Command;

/** Every minute: uploads approved YouTube posts whose time has come, inside each post's own workspace.  A post is claimed once; daily caps protect the shared YouTube quota. */
final class SocialYoutubeCommand extends Command
{
    protected $signature = 'social:youtube {--limit=1}';

    protected $description = 'Upload due, approved social posts to their YouTube channels';

    public function handle(TenantContext $tenants, SocialPublishRepo $repo, YoutubePublisher $publisher): int
    {
        $jobs = $repo->claimYoutube(max(1, min(3, (int) $this->option('limit'))), max(0, (int) config('social.youtube_daily_uploads', 5)), max(0, (int) config('social.youtube_daily_uploads_per_workspace', 3)));
        $posted = 0;
        foreach ($jobs as $c) {
            try {
                if ($tenants->run($c['tenant_id'], fn () => $publisher->publish($c)) === 'posted') {
                    $posted++;
                }
            } catch (\Throwable $e) {
                report($e);
                $this->error("post {$c['id']} failed: ".$e->getMessage());
            }
        }
        if ($jobs !== []) {
            $this->line('claimed '.count($jobs).', uploaded '.$posted);
        }

        return self::SUCCESS;
    }
}
