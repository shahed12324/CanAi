<?php

declare(strict_types=1);

namespace App\Office;

interface OfficeRepo
{
    /** @return list<array{id: string, kind: string, title: string, request: string, created_at: string}> newest first */
    public function list(string $userId, int $limit = 100): array;

    /** @return array{id: string, kind: string, title: string, spec: array<string, mixed>}|null only the person's own file */
    public function find(string $userId, string $id): ?array;

    public function count(string $userId): int;

    /** @param array<string, mixed> $spec @return string ok|bad_input|limit */
    public function save(string $tenantId, string $userId, string $id, string $kind, string $title, string $request, array $spec): string;

    /** @return string ok|not_found */
    public function delete(string $tenantId, string $userId, string $id): string;
}
