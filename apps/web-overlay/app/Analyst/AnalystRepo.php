<?php

declare(strict_types=1);

namespace App\Analyst;

interface AnalystRepo
{
    /** @return list<array{ym: string, type: string, account_id: string, name: string, amount_minor: int}> income / expense per month and account (income = credit - debit, expense = debit - credit) */
    public function monthly(string $from, string $to): array;

    /** @return list<array{ym: string, amount_minor: int}> net movement (debit - credit) of cash/bank/wallet accounts per month */
    public function cashMonthly(string $from, string $to): array;

    /** Cash + bank + wallet balance as of the date. */
    public function cashBalance(string $asof): int;

    /** @return array{receivable: int, payable: int} balances today */
    public function openItems(): array;

    /** @param array<string, mixed> $facts @param array<string, mixed> $forecast @return string ok|bad_input */
    public function saveReport(string $tenantId, string $userId, string $id, string $month, array $facts, array $forecast, string $narrative, string $source, ?string $note, ?string $model, int $in, int $out, string $credits): string;

    /** @return array{id: string, month_to: string, facts: array<string, mixed>, forecast: array<string, mixed>, narrative: string, source: string, note: ?string, model: ?string, credits_charged: string, created_at: string}|null */
    public function report(string $id): ?array;

    /** @return list<array{id: string, month_to: string, source: string, created_at: string}> */
    public function recent(int $limit): array;
}
