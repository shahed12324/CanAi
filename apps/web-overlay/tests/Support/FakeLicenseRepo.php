<?php

namespace Tests\Support;

use App\License\LicenseRepo;

final class FakeLicenseRepo implements LicenseRepo
{
    public ?array $row = null;

    public ?int $left = null;

    public function seatsLeft(string $tenantId): ?int { return $this->left; }

    public function get(string $tenantId): ?array { return $this->row; }
}
