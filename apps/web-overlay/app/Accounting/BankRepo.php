<?php

declare(strict_types=1);

namespace App\Accounting;

interface BankRepo
{
    /** Cash / bank / wallet accounts that can take a statement. @return list<array{id: string, code: string, name: string, name_bn: ?string}> */
    public function accounts(): array;

    /** @return string[]|array{result: string, rows_new: int, rows_dup: int} */
    public function import(string $tenantId, string $userId, string $accountId, string $filename, array $rows): array;

    /** @return list<array<string, mixed>> */
    public function lines(string $accountId, string $status, int $limit = 200): array;

    /** @return list<array<string, mixed>> open lines with their candidate journal lines */
    public function suggestions(string $accountId, int $window = 3): array;

    public function match(string $lineId, int $journalLineSeq, string $userId): string;

    public function unmatch(string $lineId): string;

    public function ignore(string $lineId, bool $on): string;

    public function createEntry(string $lineId, string $userId, string $counterAccountId, ?string $memo): string;

    public function autoMatch(string $accountId, string $userId, int $window = 3): int;

    /** @return array<string, mixed> */
    public function reconciliation(string $accountId, string $asof): array;

    /** @return list<array<string, mixed>> */
    public function imports(string $accountId, int $limit = 10): array;
}
