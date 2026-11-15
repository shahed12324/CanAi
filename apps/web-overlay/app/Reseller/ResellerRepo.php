<?php

declare(strict_types=1);

namespace App\Reseller;

interface ResellerRepo
{
    /** ok|already|not_found|not_owner|self */
    public function join(string $tenantId, string $userId, string $code): string;

    /** The reseller (name) this workspace joined, or null. */
    public function of(string $tenantId, string $userId): ?string;

    /** @return ?array{id: string, name: string, code: string, commission_bp: int, status: string} */
    public function me(string $tenantId, string $userId): ?array;

    /** @return ?array{customers: list<array{name: string, joined_at: string, paid_orders: int}>, money: array<string, array{revenue: int, commission: int, paid_out: int, due: int}>} */
    public function report(string $tenantId, string $userId): ?array;
}
