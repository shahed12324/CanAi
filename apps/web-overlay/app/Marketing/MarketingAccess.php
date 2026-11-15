<?php

declare(strict_types=1);

namespace App\Marketing;

/** Who may look at what.  A workflow runs in the background as the person who owns it, so every marketing tool re-checks the person's permissions at run time. */
interface MarketingAccess
{
    public function canViewAds(string $tenantId, string $userId): bool;

    public function canViewSocial(string $tenantId, string $userId): bool;
}
