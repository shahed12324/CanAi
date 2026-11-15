<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Social\SocialPublisher;
use App\Social\SocialPublishRepo;
use App\Tenancy\TenantContext;
use Illuminate\Console\Command;

/** Every minute: publishes approved posts whose time has come to their Facebook Page, inside each post's own workspace.  A post is claimed once; see phase23b.sql. */
final class SocialPublishCommand extends Command
{
    protected $signature = 'social:publish {--limit=3}';

    protected $description = 'Publish due, approved social posts to their Facebook Pages';

    public function handle(TenantContext $tenants, SocialPublishRepo $repo, SocialPublisher $publisher): int
    {
        $jobs = $repo->claim(max(1, min(5, (int) $this->option('limit'))));
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
            $this->line('claimed '.count($jobs).', posted '.$posted);
        }

        return self::SUCCESS;
    }
}
