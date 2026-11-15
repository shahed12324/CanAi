<?php

declare(strict_types=1);

namespace App\Audit;

interface AuditRepo
{
    /** @return array{dup_days: int, outlier_factor: int, min_history: int, backdate_days: int, cash_limit_minor: int, round_modulus: int, round_min_minor: int} */
    public function settings(): array;

    /** @return string ok|bad_input */
    public function saveSettings(string $tenantId, string $userId, array $v): string;

    /** @return int number of NEW findings */
    public function scan(string $tenantId, string $userId): int;

    /** @return list<array<string, mixed>> */
    public function findings(?string $status, int $limit): array;

    /** @return array<string, int> status => count (open, confirmed, dismissed) */
    public function counts(): array;

    /** @return string ok|need_note|bad_status */
    public function review(string $tenantId, string $userId, string $id, string $status, ?string $note): string;

    /** @return list<array{key: string, ok: bool, count: int, amount_minor: int}> */
    public function health(string $tenantId): array;

    /** @return list<array{id: string, label: string, sort: int, active: bool, done_on: ?string, note: ?string}> checklist for one month */
    public function checklist(string $period): array;

    /** @return string ok|bad_input */
    public function saveItem(string $tenantId, string $userId, ?string $id, string $label, int $sort): string;

    public function setItemActive(string $tenantId, string $id, bool $active): string;

    /** @return string ok|bad_period|bad_date|already */
    public function tick(string $tenantId, string $userId, string $id, string $period, string $on, ?string $note): string;

    /** @return string ok|missing */
    public function untick(string $tenantId, string $id, string $period): string;

    /** @return string ok|already */
    public function seedItems(string $tenantId, string $userId): string;
}
