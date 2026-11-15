<?php

declare(strict_types=1);

namespace App\Accounting;

interface AccountingRepo
{
    /** @return array{locked_through: ?string, seeded: bool} */
    public function settings(): array;

    /** Adds the Bangladeshi chart of accounts (idempotent). @return int accounts added */
    public function seed(string $tenantId): int;

    /** @return list<array{id: string, code: string, name: string, name_bn: ?string, type: string, parent_id: ?string, postable: bool, system_key: ?string, active: bool}> */
    public function accounts(): array;

    /** @return string ok|bad_code|duplicate_code|bad_parent|bad_name */
    public function addAccount(string $tenantId, string $code, string $name, ?string $nameBn, string $type, ?string $parentId): string;

    public function setActive(string $accountId, bool $active): void;

    /** @param list<array{account_id: string, debit_minor: int, credit_minor: int, memo: ?string}> $lines @return array{result: string, entry_id: ?string, number: ?int} */
    public function post(string $tenantId, string $userId, string $date, string $memo, array $lines): array;

    /** @return string ok|already_reversed|is_reversal|locked|bad_date (a foreign id raises) */
    public function reverse(string $entryId, string $userId, string $date, ?string $memo): string;

    /** @return list<array<string, mixed>> */
    public function entries(int $limit = 100): array;

    /** @return array{entry: array<string, mixed>, lines: list<array<string, mixed>>, reversed_by: ?array<string, mixed>}|null */
    public function entry(string $id): ?array;

    /** @return list<array<string, mixed>> */
    public function trialBalance(string $asof): array;

    /** @return list<array<string, mixed>> */
    public function ledger(string $accountId, string $from, string $to): array;

    /** @return string ok|not_forward|bad_date */
    public function lock(string $tenantId, string $userId, string $through): string;

    /** @return string ok|no_reason|not_back|not_locked */
    public function unlock(string $tenantId, string $userId, ?string $to, string $reason): string;

    /** @return list<array<string, mixed>> */
    public function lockLog(int $limit = 20): array;
}
