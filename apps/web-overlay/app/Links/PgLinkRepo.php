<?php

declare(strict_types=1);

namespace App\Links;

use Illuminate\Support\Facades\DB;

final class PgLinkRepo implements LinkRepo
{
    private const COLS = 'id, kind, name, secret_ct, secret_ver, host, last_used_at, last_error';

    private static function row(object $r): array
    {
        return ['id' => trim((string) $r->id), 'kind' => (string) $r->kind, 'name' => (string) $r->name, 'secret_ct' => (string) $r->secret_ct, 'secret_ver' => (int) $r->secret_ver, 'host' => (string) $r->host,
            'last_used_at' => $r->last_used_at === null ? null : (string) $r->last_used_at, 'last_error' => $r->last_error === null ? null : (string) $r->last_error];
    }

    public function list(string $userId, string $kind): array
    {
        return array_map(self::row(...), DB::select('select '.self::COLS.' from user_links where user_id = ? and kind = ? order by created_at, name', [$userId, $kind]));
    }

    public function find(string $userId, string $id): ?array
    {
        $r = DB::selectOne('select '.self::COLS.' from user_links where user_id = ? and id = ?', [$userId, $id]);

        return $r === null ? null : self::row($r);
    }

    public function findByName(string $userId, string $kind, string $name): ?array
    {
        $r = DB::selectOne('select '.self::COLS.' from user_links where user_id = ? and kind = ? and lower(name) = lower(?)', [$userId, $kind, trim($name)]);

        return $r === null ? null : self::row($r);
    }

    public function add(string $tenantId, string $userId, string $id, string $kind, string $name, string $cipher, int $version, string $host): string
    {
        return (string) DB::selectOne('select link_add(?, ?, ?, ?, ?, ?, ?, ?) as r', [$id, $tenantId, $userId, $kind, $name, $cipher, $version, $host])->r;
    }

    public function delete(string $tenantId, string $userId, string $id): string
    {
        return (string) DB::selectOne('select link_delete(?, ?, ?) as r', [$id, $tenantId, $userId])->r;
    }

    public function updateSecret(string $tenantId, string $userId, string $id, string $cipher, int $version): string
    {
        return (string) DB::selectOne('select link_secret_update(?, ?, ?, ?, ?) as r', [$id, $tenantId, $userId, $cipher, $version])->r;
    }

    public function touch(string $tenantId, string $userId, string $id, ?string $error): string
    {
        return (string) DB::selectOne('select link_touch(?, ?, ?, ?) as r', [$id, $tenantId, $userId, $error])->r;
    }
}
