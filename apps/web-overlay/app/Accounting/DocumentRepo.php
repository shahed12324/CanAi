<?php

declare(strict_types=1);

namespace App\Accounting;

interface DocumentRepo
{
    /** @return list<array{id: string, kind: string, name: string, phone: ?string, note: ?string, active: bool}> */
    public function parties(?string $kind = null): array;

    public function addParty(string $tenantId, string $kind, string $name, ?string $phone, ?string $note): string;

    public function setPartyActive(string $partyId, bool $active): void;

    /**
     * @param list<array{account_id: string, description: ?string, amount_minor: int}> $lines
     * @return array{result: string, doc_id: ?string, number: ?string, total_minor: ?int}
     */
    public function create(string $tenantId, string $userId, string $kind, ?string $partyId, string $date, ?string $due, ?string $reference, array $lines, int $vatMinor, ?string $payAccountId): array;

    /** @return list<array<string, mixed>> */
    public function docs(string $kind, int $limit = 100): array;

    /** @return array{doc: array<string, mixed>, lines: list<array<string, mixed>>, payments: list<array<string, mixed>>}|null */
    public function doc(string $id): ?array;

    /** @return string ok|bad_amount|bad_account|not_payable|overpay|locked|bad_date|... */
    public function pay(string $docId, string $userId, string $date, int $amountMinor, string $accountId, ?string $note): string;

    public function voidPayment(string $paymentId, string $userId, string $date): string;

    public function voidDoc(string $docId, string $userId, string $date): string;

    /** @return list<array<string, mixed>> */
    public function aging(string $kind, string $asof): array;

    /** @return list<array<string, mixed>> */
    public function cashBalances(string $asof): array;

    public function markCash(string $accountId, bool $on): string;
}
