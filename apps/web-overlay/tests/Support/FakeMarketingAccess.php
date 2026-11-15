<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Marketing\MarketingAccess;

final class FakeMarketingAccess implements MarketingAccess
{
    public function __construct(public bool $ads = true, public bool $social = true) {}

    public function canViewAds(string $tenantId, string $userId): bool
    {
        return $this->ads;
    }

    public function canViewSocial(string $tenantId, string $userId): bool
    {
        return $this->social;
    }
}
