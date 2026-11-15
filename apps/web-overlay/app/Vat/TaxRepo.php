<?php

declare(strict_types=1);

namespace App\Vat;

interface TaxRepo
{
    /** @return list<array{id: string, kind: string, code: string, label: string, rate_bp: int, effective_from: string, effective_to: ?string}> */
    public function rules(): array;

    /** @return string ok|bad_input|bad_date|overlap */
    public function saveRule(string $tenantId, string $userId, string $kind, string $code, string $label, int $bp, string $from): string;

    /** @param list<array<string, mixed>> $items @return string ok|bad_items|bad_amount|overpay|not_payable|bad_account|no_system_account|... */
    public function settle(string $userId, string $docId, string $date, int $grossMinor, string $accountId, ?string $note, array $items): string;

    /** @return string ok|bad_cert */
    public function setCert(string $withholdingId, string $userId, ?string $cert): string;

    /** @return array{result: string, amount_minor: int} */
    public function deposit(string $tenantId, string $userId, string $kind, string $date, string $upto, string $accountId, ?string $challan): array;

    /** @return string ok|bad_amount|bad_account|bad_kind|no_system_account|... */
    public function advancePay(string $tenantId, string $userId, string $date, int $amountMinor, string $accountId, ?string $ref, ?string $note): string;

    /** @return string ok|already_void|locked|bad_date */
    public function advanceVoid(string $advanceId, string $userId, string $date): string;

    /** @return array{owed: array<string, int>, deposited: array<string, int>, suffered: array<string, int>, advance_minor: int} per kind, live (cancelled payments excluded) */
    public function summary(): array;

    /** @return list<array<string, mixed>> */
    public function withholdings(int $limit): array;

    /** @return list<array<string, mixed>> */
    public function deposits(int $limit): array;

    /** @return list<array<string, mixed>> */
    public function advances(int $limit): array;

    /** @return list<array<string, mixed>> */
    public function cashAccounts(): array;
}
