<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Office\OfficeRepo;

final class FakeOfficeRepo implements OfficeRepo
{
    /** @var array<string, array<string, mixed>> */
    public array $files = [];

    public string $saveResult = 'ok';

    public function list(string $userId, int $limit = 100): array
    {
        return array_values(array_filter($this->files, fn ($f) => $f['user'] === $userId));
    }

    public function find(string $userId, string $id): ?array
    {
        $f = $this->files[$id] ?? null;

        return ($f !== null && $f['user'] === $userId) ? $f : null;
    }

    public function count(string $userId): int
    {
        return count($this->list($userId));
    }

    public function save(string $tenantId, string $userId, string $id, string $kind, string $title, string $request, array $spec): string
    {
        if ($this->saveResult !== 'ok') {
            return $this->saveResult;
        }
        $this->files[$id] = ['id' => $id, 'user' => $userId, 'kind' => $kind, 'title' => $title, 'request' => $request, 'spec' => $spec, 'created_at' => 'now'];

        return 'ok';
    }

    public function delete(string $tenantId, string $userId, string $id): string
    {
        if ($this->find($userId, $id) === null) {
            return 'not_found';
        }
        unset($this->files[$id]);

        return 'ok';
    }
}
