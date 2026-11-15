<?php

declare(strict_types=1);

namespace App\BillScan;

/** Storage for scans. All rules (one live scan per file, post once, tenant isolation) are enforced by the bill_scan_* database functions. */
interface BillScanRepo
{
    /** @return array{result: string, scan_id: ?string} result: ok | duplicate | bad_input */
    public function start(string $tenantId, string $userId, string $name, string $mime, int $size, string $sha): array;

    /**
     * @param  array<string, mixed>  $extracted
     * @param  list<string>  $flags
     * @return string ok | not_scanning | bad_input
     */
    public function ready(string $scanId, array $extracted, array $flags, ?string $model, int $in, int $out, string $credits): string;

    /** @return string ok | not_scanning */
    public function fail(string $scanId, string $error, ?string $model, int $in, int $out, string $credits): string;

    /** @return string ok | not_open */
    public function discard(string $scanId, string $userId): string;

    /**
     * @param  list<array{account_id: string, description: ?string, amount_minor: int}>  $lines
     * @return array{result: string, doc_id: ?string, number: ?string, total_minor: ?int}
     */
    public function post(string $scanId, string $userId, string $kind, ?string $partyId, string $date, ?string $due, ?string $reference, array $lines, int $vatMinor, ?string $payAccountId): array;

    /** @return array<string, mixed>|null */
    public function scan(string $scanId): ?array;

    /** @return list<array<string, mixed>> */
    public function recent(int $limit): array;
}
