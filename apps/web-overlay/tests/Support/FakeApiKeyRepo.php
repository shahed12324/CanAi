<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Developer\ApiKeyRepo;

final class FakeApiKeyRepo implements ApiKeyRepo
{
    /** @var array<string, array<string, mixed>> */
    public array $keys = [];

    public ?string $createResult = null;

    public function create(string $tenantId, string $userId, string $id, string $name, string $prefix, string $hash, array $scopes, ?int $days): string
    {
        if ($this->createResult !== null) {
            return $this->createResult;
        }
        $this->keys[$id] = ['id' => $id, 'tenant' => $tenantId, 'user' => $userId, 'name' => $name, 'prefix' => $prefix, 'hash' => $hash, 'scopes' => $scopes, 'days' => $days, 'revoked' => false];

        return 'ok';
    }

    public function revoke(string $tenantId, string $userId, string $id): string
    {
        if (($this->keys[$id]['user'] ?? null) !== $userId || $this->keys[$id]['revoked']) {
            return 'not_found';
        }
        $this->keys[$id]['revoked'] = true;

        return 'ok';
    }

    public function list(string $userId): array
    {
        return [];
    }

    public function usage(string $userId): array
    {
        return [];
    }

    public function resolve(string $hash): ?array
    {
        foreach ($this->keys as $k) {
            if ($k['hash'] === $hash && ! $k['revoked']) {
                return ['key_id' => $k['id'], 'tenant_id' => $k['tenant'], 'user_id' => $k['user'], 'scopes' => $k['scopes']];
            }
        }

        return null;
    }
}
