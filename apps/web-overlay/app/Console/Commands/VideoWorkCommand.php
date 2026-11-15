<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Tenancy\TenantContext;
use App\Video\VideoRepo;
use App\Video\VideoService;
use Illuminate\Console\Command;

/** Every minute: takes the oldest waiting video and renders it inside its own workspace.  One at a time (the engine renders one at a time too). */
final class VideoWorkCommand extends Command
{
    protected $signature = 'video:work {--limit=1}';

    protected $description = 'Render queued slide videos';

    public function handle(TenantContext $tenants, VideoRepo $repo, VideoService $video): int
    {
        $jobs = $repo->claim(max(1, min(3, (int) $this->option('limit'))));
        foreach ($jobs as $j) {
            try {
                $tenants->run($j['tenant_id'], fn () => $video->work($j['tenant_id'], $j['id']));
            } catch (\Throwable $e) {
                report($e);
                $this->error("video {$j['id']} failed: ".$e->getMessage());
            }
        }
        if ($jobs !== []) {
            $this->line('rendered '.count($jobs));
        }

        return self::SUCCESS;
    }
}
