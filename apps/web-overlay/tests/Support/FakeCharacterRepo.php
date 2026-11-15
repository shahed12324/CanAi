<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Character\CharacterRepo;

final class FakeCharacterRepo implements CharacterRepo
{
    /** @var array<string, array<string, mixed>> */
    public array $rows = [];

    private int $n = 0;

    public function all(string $tenantId): array
    {
        return array_values(array_filter($this->rows, fn ($r) => $r['tenant'] === $tenantId && ! $r['deleted']));
    }

    public function find(string $tenantId, string $id): ?array
    {
        $r = $this->rows[$id] ?? null;

        return ($r !== null && $r['tenant'] === $tenantId && ! $r['deleted']) ? $r : null;
    }

    public function create(string $tenantId, string $userId, array $data): string
    {
        $id = str_pad('ch'.(++$this->n), 26, '0', STR_PAD_LEFT);
        $this->rows[$id] = ['id' => $id, 'tenant' => $tenantId, 'created_by' => $userId, 'deleted' => false] + $data;

        return $id;
    }

    public function update(string $tenantId, string $userId, string $id, array $data): bool
    {
        $r = $this->find($tenantId, $id);
        if ($r === null || $r['created_by'] !== $userId) {
            return false;
        }
        $this->rows[$id] = $data + $r;

        return true;
    }

    public function delete(string $tenantId, string $userId, string $id): bool
    {
        $r = $this->find($tenantId, $id);
        if ($r === null || $r['created_by'] !== $userId) {
            return false;
        }
        $this->rows[$id]['deleted'] = true;

        return true;
    }

    public function count(string $tenantId): int
    {
        return count($this->all($tenantId));
    }
}
