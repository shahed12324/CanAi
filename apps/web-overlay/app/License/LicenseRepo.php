<?php

declare(strict_types=1);

namespace App\License;

interface LicenseRepo
{
    /** Seats still free, null when the workspace has no licence, 0 when it has expired. */
    public function seatsLeft(string $tenantId): ?int;

    /** @return ?array{seats: int, expires_at: string, note: string, used: int} the workspace's own licence */
    public function get(string $tenantId): ?array;
}
