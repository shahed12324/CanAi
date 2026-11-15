<?php

declare(strict_types=1);

namespace App\Workflow\Tools;

use App\Marketing\MarketingAccess;
use App\Marketing\MarketingSnapshot;
use App\Reports\ReportPeriod;
use App\Reports\ReportRepo;
use App\Workflow\Risk;
use App\Workflow\Tool;
use App\Workflow\ToolContext;

/** Ad and social numbers of the last week or month (copied data only; nothing is fetched, nothing is changed).  Re-checks the owner's permissions: ads need ads.view, the social part needs social.view. */
final class MarketingSnapshotTool implements Tool
{
    public function __construct(private readonly ReportRepo $repo, private readonly MarketingAccess $access) {}

    public function key(): string
    {
        return 'marketing.snapshot';
    }

    public function label(): string
    {
        return 'Marketing numbers: ads and social (read-only)';
    }

    public function risk(): string
    {
        return Risk::READ;
    }

    public function args(): array
    {
        return ['period?' => 'week (last 7 days, default) or month (last 30 days)'];
    }

    public function run(ToolContext $c, array $args): string
    {
        $canAds = $this->access->canViewAds($c->tenantId, $c->userId);
        $canSocial = $this->access->canViewSocial($c->tenantId, $c->userId);
        if (! $canAds && ! $canSocial) {
            return '(No permission to view marketing data)';
        }
        $kind = strtolower(trim($args['period'] ?? '')) === 'month' ? 'month' : 'week';
        $zone = new \DateTimeZone($c->timezone);
        $now = new \DateTimeImmutable('now', $zone);
        $range = ReportPeriod::range($kind, $now);
        if ($range === null) {
            return '(The period is not valid)';
        }
        $ads = null;
        $prev = [];
        if ($canAds) {
            $ads = ['meta' => $this->repo->ads('meta', $range['from'], $range['to']), 'google' => $this->repo->ads('google', $range['from'], $range['to'])];
            if ($range['prev_from'] !== null) {
                $prev = ['meta' => $this->repo->ads('meta', $range['prev_from'], (string) $range['prev_to']), 'google' => $this->repo->ads('google', $range['prev_from'], (string) $range['prev_to'])];
            }
        }

        return MarketingSnapshot::text(['range' => $range, 'now' => $now, 'ads' => $ads, 'prev' => $prev, 'social' => $canSocial ? $this->repo->social($range['from'], $range['to'], $c->timezone) : null]);
    }
}
