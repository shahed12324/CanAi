<?php

declare(strict_types=1);

namespace App\Vat;

interface TaxCalRepo
{
    /** @return list<array{id: string, label: string, kind: string, freq: string, due_day: ?int, due_month: ?int, once_date: ?string, remind_days: int, active: bool}> */
    public function obligations(): array;

    /** @return array<string, string> "obligationId|due" => done_on, for due dates in from..to */
    public function doneMap(string $from, string $to): array;

    /** @return string ok|bad_input|bad_schedule  (unknown $id creates, known changes) */
    public function save(string $tenantId, string $userId, ?string $id, string $label, string $kind, string $freq, ?int $day, ?int $month, ?string $once, int $remind): string;

    public function setActive(string $tenantId, string $id, bool $active): string;

    /** @return string ok|bad_due|bad_date|already */
    public function markDone(string $tenantId, string $userId, string $id, string $due, string $doneOn, ?string $note): string;

    /** @return string ok|missing */
    public function undo(string $tenantId, string $id, string $due): string;

    /** @return string ok|already */
    public function seed(string $tenantId, string $userId): string;
}
