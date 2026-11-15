<?php

declare(strict_types=1);

namespace App\Payroll;

interface PayrollRepo
{
    /** @return list<array{id: string, code: string, name: string, designation: ?string, join_date: string, end_date: ?string, active: bool, gross_minor: int}> gross = earnings in force today */
    public function employees(): array;

    /** @return array{id: string, code: string, name: string, designation: ?string, join_date: string, end_date: ?string, active: bool}|null */
    public function employee(string $id): ?array;

    /** @return string ok|bad_input|bad_date|code_taken */
    public function saveEmployee(string $tenantId, string $userId, ?string $id, string $code, string $name, ?string $designation, string $join, ?string $end): string;

    public function setEmployeeActive(string $tenantId, string $id, bool $active): string;

    /** @return list<array{id: string, kind: string, is_tax: bool, label: string, amount_minor: int, effective_from: string, effective_to: ?string}> */
    public function components(string $employeeId): array;

    /** @return string ok|bad_input|bad_date|overlap */
    public function saveComponent(string $tenantId, string $userId, string $employeeId, string $kind, bool $tax, string $label, int $amountMinor, string $from): string;

    /** @return string ok|bad_date|already_ended */
    public function endComponent(string $tenantId, string $id, string $to): string;

    /** @return list<array<string, mixed>> */
    public function runs(int $limit): array;

    /** @return array{run: array<string, mixed>, lines: list<array<string, mixed>>}|null */
    public function run(string $id): ?array;

    /** @return string ok|bad_period|exists|nothing|negative_net */
    public function createRun(string $tenantId, string $userId, string $id, string $period): string;

    /** @return string ok|bad_input|not_draft|no_line|negative_net */
    public function adjustAdd(string $tenantId, string $runId, string $employeeId, string $kind, string $label, int $amountMinor, bool $tax): string;

    /** @return string ok|not_draft|no_item|negative_net */
    public function adjustRemove(string $tenantId, string $runId, string $employeeId, string $itemId): string;

    public function post(string $tenantId, string $userId, string $runId, string $date): string;

    public function pay(string $tenantId, string $userId, string $runId, string $date, string $accountId): string;

    public function void(string $tenantId, string $userId, string $runId, string $date): string;

    /** @return list<array{id: string, code: string, name: string, name_bn: ?string}> */
    public function cashAccounts(): array;

    /** @return array<string, array{method: string, bank_name: ?string, branch: ?string, routing_no: ?string, account_no: ?string, wallet_no: ?string}> keyed by employee id */
    public function payDetails(): array;

    /** @return string ok|bad_input */
    public function savePayDetails(string $tenantId, string $userId, string $employeeId, string $method, ?string $bank, ?string $branch, ?string $routing, ?string $account, ?string $wallet): string;

    public function tenantName(string $tenantId): string;
}
