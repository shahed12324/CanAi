<?php

declare(strict_types=1);

namespace App\Vat;

interface VatRepo
{
    /** @return array{registered: bool, bin: ?string, filing_period: string} */
    public function settings(): array;

    /** @return string ok|bad_bin|bad_period */
    public function saveSettings(string $tenantId, string $userId, bool $registered, ?string $bin, string $period): string;

    /** @return list<array{id: string, code: string, label: string, rate_bp: int, effective_from: string, effective_to: ?string}> */
    public function rates(): array;

    /** @return string ok|already */
    public function seedRates(string $tenantId, string $userId): string;

    /** @return string ok|bad_input|bad_date|overlap */
    public function saveRate(string $tenantId, string $userId, string $code, string $label, int $bp, string $from): string;

    /** @return list<array<string, mixed>> invoices, bills and expenses issued in the period, with the claimable flag of purchases */
    public function docs(string $from, string $to): array;

    /** @return string ok|not_purchase|no_vat|need_reason */
    public function flagDoc(string $docId, string $userId, bool $creditable, ?string $reason): string;

    /**
     * @param  array<string, int>  $figures
     * @return array{result: string, return_id: ?string}
     */
    public function saveReturn(string $tenantId, string $userId, string $from, string $to, array $figures, ?string $note): array;

    /** @return string ok|not_draft|bad_date|bad_ref */
    public function fileReturn(string $returnId, string $userId, string $on, ?string $ref): string;

    /** @return list<array<string, mixed>> */
    public function returns(int $limit): array;

    /** @return array<string, mixed>|null */
    public function savedReturn(string $from, string $to, string $status): ?array;
}
