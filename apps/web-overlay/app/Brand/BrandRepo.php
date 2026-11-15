<?php

declare(strict_types=1);

namespace App\Brand;

interface BrandRepo
{
    /** @return ?array{enabled: bool, brand_name: ?string, color: ?string, support_email: ?string, hide_powered_by: bool} the workspace's own row */
    public function get(string $tenantId): ?array;

    /** ok|not_enabled|not_owner|bad_input */
    public function save(string $tenantId, string $userId, ?string $name, ?string $color, ?string $email, bool $hide): string;
}
