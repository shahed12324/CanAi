<?php

declare(strict_types=1);

namespace App\Links;

interface LinkRepo
{
    /** @return list<array{id: string, kind: string, name: string, secret_ct: string, secret_ver: int, host: string, last_used_at: ?string, last_error: ?string}> this person's links of one kind, oldest first */
    public function list(string $userId, string $kind): array;

    /** @return ?array{id: string, kind: string, name: string, secret_ct: string, secret_ver: int, host: string, last_used_at: ?string, last_error: ?string} */
    public function find(string $userId, string $id): ?array;

    /** @return ?array{id: string, kind: string, name: string, secret_ct: string, secret_ver: int, host: string, last_used_at: ?string, last_error: ?string} */
    public function findByName(string $userId, string $kind, string $name): ?array;

    /** @return string ok|bad_input|limit|duplicate */
    public function add(string $tenantId, string $userId, string $id, string $kind, string $name, string $cipher, int $version, string $host): string;

    /** @return string ok|not_found */
    public function delete(string $tenantId, string $userId, string $id): string;

    /** Replaces the stored secret of one of the person's calendar links (a rotated refresh token). @return string ok|bad_input|not_found */
    public function updateSecret(string $tenantId, string $userId, string $id, string $cipher, int $version): string;

    /** @return string ok|not_found */
    public function touch(string $tenantId, string $userId, string $id, ?string $error): string;
}
