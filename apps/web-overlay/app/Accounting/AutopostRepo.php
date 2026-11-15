<?php

declare(strict_types=1);

namespace App\Accounting;

interface AutopostRepo
{
    public function enabled(): bool;

    /** @return string ok|not_seeded|no_system_account */
    public function setEnabled(string $tenantId, string $userId, bool $on): string;

    /** @return array{pending: int, failed: int, posted: int, skipped: int} */
    public function counts(): array;

    /** Waiting (pending / failed) rows first, then the latest closed ones. @return list<array<string, mixed>> */
    public function queue(int $limit): array;

    /** @return array{posted: int, failed: int, skipped: int} */
    public function run(string $tenantId, string $userId, int $limit): array;

    /** @return string ok|not_open */
    public function skip(int $seq, string $userId, string $reason): string;

    /** @return list<array{courier: string, orders: int, delivered_minor: int, returned_minor: int, settled_minor: int, outstanding_minor: int}> */
    public function receivable(): array;

    /** @return list<array<string, mixed>> */
    public function settlements(int $limit): array;

    public function settle(string $tenantId, string $userId, string $date, string $courier, int $gross, int $fee, string $accountId, ?string $note): string;

    public function voidSettlement(string $id, string $userId, string $date): string;

    /** @return list<array{id: string, code: string, name: string, name_bn: ?string}> */
    public function cashAccounts(): array;
}
