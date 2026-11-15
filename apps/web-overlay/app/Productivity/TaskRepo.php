<?php

declare(strict_types=1);

namespace App\Productivity;

interface TaskRepo
{
    /** @return list<array{id: string, title: string, details: string, priority: int, due_on: ?string, remind_at: ?string, reminded: bool, status: string, done_at: ?string}> this person's tasks: open first (by due date, importance), then the latest finished */
    public function tasks(string $userId, int $doneLimit = 30): array;

    /** @return string ok|bad_input|limit */
    public function add(string $tenantId, string $userId, string $id, string $title, string $details, int $priority, ?string $due, ?string $remind): string;

    /** @return string ok|not_found|bad_input */
    public function edit(string $tenantId, string $userId, string $id, string $title, string $details, int $priority, ?string $due, ?string $remind): string;

    /** @return string ok|not_found */
    public function setDone(string $tenantId, string $userId, string $id, bool $done): string;

    /** @return string ok|not_found */
    public function delete(string $tenantId, string $userId, string $id): string;

    public function clearDone(string $tenantId, string $userId): int;

    /** @return list<array{id: string, title: string, body: string, pinned: bool, updated_at: string}> */
    public function notes(string $userId): array;

    /** @return string ok|bad_input|limit|not_found */
    public function saveNote(string $tenantId, string $userId, string $id, string $title, string $body, ?bool $pinned, bool $isNew): string;

    /** @return string ok|not_found */
    public function deleteNote(string $tenantId, string $userId, string $id): string;
}
