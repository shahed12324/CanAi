<?php

declare(strict_types=1);

namespace App\Character;

/** Characters of one workspace (RLS confines every query; tenant ids are also explicit). */
interface CharacterRepo
{
    /** @return list<array<string, mixed>> live characters, oldest first */
    public function all(string $tenantId): array;

    /** @return ?array<string, mixed> */
    public function find(string $tenantId, string $id): ?array;

    /** @param array<string, mixed> $data validated fields */
    public function create(string $tenantId, string $userId, array $data): string;

    /** Only the creator may change it. @param array<string, mixed> $data */
    public function update(string $tenantId, string $userId, string $id, array $data): bool;

    /** Only the creator may delete it (soft). */
    public function delete(string $tenantId, string $userId, string $id): bool;

    public function count(string $tenantId): int;
}
