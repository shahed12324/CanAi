<?php

declare(strict_types=1);

namespace App\Marketing;

use App\Models\User;
use App\Tenancy\TenantRoles;

final class RoleMarketingAccess implements MarketingAccess
{
    public function canViewAds(string $tenantId, string $userId): bool
    {
        return $this->can($tenantId, $userId, 'ads.view');
    }

    public function canViewSocial(string $tenantId, string $userId): bool
    {
        return $this->can($tenantId, $userId, 'social.view');
    }

    private function can(string $tenantId, string $userId, string $permission): bool
    {
        $user = User::query()->find($userId);
        if ($user === null) {
            return false;
        }

        return (bool) TenantRoles::inTeam($tenantId, fn () => $user->can($permission));
    }
}
