<?php

declare(strict_types=1);

namespace App\Developer;

interface ApiKeyRepo
{
    /** @param list<string> $scopes @return string ok|bad_input|limit|duplicate */
    public function create(string $tenantId, string $userId, string $id, string $name, string $prefix, string $hash, array $scopes, ?int $days): string;

    /** @return string ok|not_found */
    public function revoke(string $tenantId, string $userId, string $id): string;

    /** @return list<array{id: string, name: string, prefix: string, scopes: list<string>, created_at: string, expires_at: ?string, last_used_at: ?string, revoked_at: ?string}> newest first; never the hash */
    public function list(string $userId): array;

    /** @return array<string, array{total: int, last7: int}> calls per key id (all kept days / last 7 days), only for keys that have any */
    public function usage(string $userId): array;

    /** @return ?array{key_id: string, tenant_id: string, user_id: string, scopes: list<string>} */
    public function resolve(string $hash): ?array;
}
