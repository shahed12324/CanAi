<?php

declare(strict_types=1);

namespace App\Accounting;

interface InventoryRepo
{
    /** @return list<array<string, mixed>> */
    public function valuation(string $asof): array;

    /** The Inventory account's balance in the books on a day. */
    public function bookBalance(string $asof): int;

    /** @return string ok|bad_cost|bad_date */
    public function setCost(string $variantId, int $costMinor, string $date, string $userId): string;

    /** @return list<array{effective_date: string, unit_cost_minor: int}> */
    public function costHistory(string $variantId, int $limit = 10): array;

    /** @return array{result: string, value_minor: int, book_minor: int, diff_minor: int} */
    public function revalue(string $tenantId, string $userId, string $date, string $counterAccountId): array;

    /** Income / expense accounts the difference can be posted against. @return list<array{id: string, code: string, name: string, name_bn: ?string, system_key: ?string}> */
    public function counterAccounts(): array;
}
