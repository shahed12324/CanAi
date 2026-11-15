<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\GoogleAds\GadsLaunchRepo;
use App\GoogleAds\GadsLauncher;
use App\Tenancy\TenantContext;
use Illuminate\Console\Command;

/** Every minute: creates approved ad drafts on Google as PAUSED campaigns (never active).  A draft is claimed once per attempt; at most 3 attempts per workspace per hour. */
final class GoogleAdsLaunchCommand extends Command
{
    protected $signature = 'gads:launch {--limit=1}';

    protected $description = 'Create approved Google Ads drafts as PAUSED campaigns';

    public function handle(TenantContext $tenants, GadsLaunchRepo $repo, GadsLauncher $launcher): int
    {
        $jobs = $repo->claim(max(1, min(3, (int) $this->option('limit'))), max(0, (int) config('social.ads_launches_per_hour', 3)));
        $created = 0;
        foreach ($jobs as $c) {
            try {
                if ($tenants->run($c['tenant_id'], fn () => $launcher->launch($c)) === 'created') {
                    $created++;
                }
            } catch (\Throwable $e) {
                report($e);
                $this->error("draft {$c['id']} failed: ".$e->getMessage());
            }
        }
        if ($jobs !== []) {
            $this->line('claimed '.count($jobs).', created '.$created);
        }

        return self::SUCCESS;
    }
}
