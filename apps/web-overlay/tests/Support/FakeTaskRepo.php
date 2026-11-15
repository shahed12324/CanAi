<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Productivity\TaskRepo;

final class FakeTaskRepo implements TaskRepo
{
    /** @var list<array<string, mixed>> */
    public array $tasks = [];

    /** @var list<array<string, mixed>> */
    public array $notes = [];

    public string $addResult = 'ok';

    public string $noteResult = 'ok';

    public function tasks(string $userId, int $doneLimit = 30): array
    {
        return array_values(array_filter($this->tasks, fn ($t) => $t['user'] === $userId));
    }

    public function add(string $tenantId, string $userId, string $id, string $title, string $details, int $priority, ?string $due, ?string $remind): string
    {
        if ($this->addResult === 'ok') {
            $this->tasks[] = ['id' => $id, 'user' => $userId, 'title' => $title, 'details' => $details, 'priority' => $priority, 'due_on' => $due, 'remind_at' => $remind, 'reminded' => false, 'status' => 'open', 'done_at' => null];
        }

        return $this->addResult;
    }

    public function edit(string $tenantId, string $userId, string $id, string $title, string $details, int $priority, ?string $due, ?string $remind): string
    {
        return 'ok';
    }

    public function setDone(string $tenantId, string $userId, string $id, bool $done): string
    {
        return 'ok';
    }

    public function delete(string $tenantId, string $userId, string $id): string
    {
        return 'ok';
    }

    public function clearDone(string $tenantId, string $userId): int
    {
        return 0;
    }

    public function notes(string $userId): array
    {
        return [];
    }

    public function saveNote(string $tenantId, string $userId, string $id, string $title, string $body, ?bool $pinned, bool $isNew): string
    {
        if ($this->noteResult === 'ok') {
            $this->notes[] = ['user' => $userId, 'title' => $title, 'body' => $body];
        }

        return $this->noteResult;
    }

    public function deleteNote(string $tenantId, string $userId, string $id): string
    {
        return 'ok';
    }
}
