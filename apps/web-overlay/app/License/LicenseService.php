<?php

declare(strict_types=1);

namespace App\License;

/** Enterprise seat licences: friendly checks before the database trigger (which is the real enforcement) says no. */
final class LicenseService
{
    public const MESSAGE_FULL = 'All seats of your Enterprise license are full. Contact the platform admin to add seats.';

    public const MESSAGE_EXPIRED = 'Your Enterprise license has expired — new members cannot be added until it is renewed.';

    public function __construct(private readonly LicenseRepo $repo) {}

    /** Why a new person cannot be invited right now, or null when they can. */
    public function inviteBlockedReason(string $tenantId): ?string
    {
        $left = $this->repo->seatsLeft($tenantId);
        if ($left === null || $left > 0) {
            return null;
        }
        $l = $this->repo->get($tenantId);

        return $l !== null && strtotime($l['expires_at']) <= time() ? self::MESSAGE_EXPIRED : self::MESSAGE_FULL;
    }

    /** @return ?array{seats: int, used: int, left: int, expires_at: string, expired: bool, note: string} */
    public function status(string $tenantId): ?array
    {
        $l = $this->repo->get($tenantId);
        if ($l === null) {
            return null;
        }
        $expired = strtotime($l['expires_at']) <= time();

        return $l + ['left' => $expired ? 0 : max(0, $l['seats'] - $l['used']), 'expired' => $expired];
    }
}
