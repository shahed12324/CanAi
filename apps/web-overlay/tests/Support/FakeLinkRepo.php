<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Links\LinkRepo;

final class FakeLinkRepo implements LinkRepo
{
    /** @var array<string, array<string, mixed>> */
    public array $rows = [];

    public int $secretUpdates = 0;

    public function list(string $userId, string $kind): array
    {
        return array_values(array_filter($this->rows, fn ($r) => $r['user'] === $userId && $r['kind'] === $kind));
    }

    public function find(string $userId, string $id): ?array
    {
        $r = $this->rows[$id] ?? null;

        return $r !== null && $r['user'] === $userId ? $r : null;
    }

    public function findByName(string $userId, string $kind, string $name): ?array
    {
        foreach ($this->list($userId, $kind) as $r) {
            if (mb_strtolower($r['name']) === mb_strtolower(trim($name))) {
                return $r;
            }
        }

        return null;
    }

    public function add(string $tenantId, string $userId, string $id, string $kind, string $name, string $cipher, int $version, string $host): string
    {
        if (trim($name) === '') {
            return 'bad_input';
        }
        if (count($this->list($userId, $kind)) >= 5) {
            return 'limit';
        }
        if ($this->findByName($userId, $kind, $name) !== null) {
            return 'duplicate';
        }
        $this->rows[$id] = ['id' => $id, 'user' => $userId, 'kind' => $kind, 'name' => trim($name), 'secret_ct' => $cipher, 'secret_ver' => $version, 'host' => $host, 'last_used_at' => null, 'last_error' => null];

        return 'ok';
    }

    public function delete(string $tenantId, string $userId, string $id): string
    {
        if ($this->find($userId, $id) === null) {
            return 'not_found';
        }
        unset($this->rows[$id]);

        return 'ok';
    }

    public function updateSecret(string $tenantId, string $userId, string $id, string $cipher, int $version): string
    {
        $r = $this->find($userId, $id);
        if ($r === null || ! in_array($r['kind'], ['gcal', 'mcal'], true)) {
            return 'not_found';
        }
        if ($cipher === '' || $version < 1) {
            return 'bad_input';
        }
        $this->secretUpdates++;
        $this->rows[$id]['secret_ct'] = $cipher;
        $this->rows[$id]['secret_ver'] = $version;
        $this->rows[$id]['last_error'] = null;

        return 'ok';
    }

    public function touch(string $tenantId, string $userId, string $id, ?string $error): string
    {
        if ($this->find($userId, $id) === null) {
            return 'not_found';
        }
        $this->rows[$id]['last_used_at'] = 'now';
        $this->rows[$id]['last_error'] = $error;

        return 'ok';
    }
}
