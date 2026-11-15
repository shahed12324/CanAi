<?php

declare(strict_types=1);

namespace App\Developer;

use Illuminate\Support\Facades\DB;

final class PgApiKeyRepo implements ApiKeyRepo
{
    public function create(string $tenantId, string $userId, string $id, string $name, string $prefix, string $hash, array $scopes, ?int $days): string
    {
        return (string) DB::selectOne('select api_key_create(?, ?, ?, ?, ?, ?, ?::text[], ?) as r', [$id, $tenantId, $userId, $name, $prefix, $hash, '{'.implode(',', $scopes).'}', $days])->r;
    }

    public function revoke(string $tenantId, string $userId, string $id): string
    {
        return (string) DB::selectOne('select api_key_revoke(?, ?, ?) as r', [$id, $tenantId, $userId])->r;
    }

    public function list(string $userId): array
    {
        return array_map(fn ($r) => ['id' => trim((string) $r->id), 'name' => (string) $r->name, 'prefix' => (string) $r->prefix, 'scopes' => explode(',', trim((string) $r->scopes, '{}')),
            'created_at' => (string) $r->created_at, 'expires_at' => $r->expires_at === null ? null : (string) $r->expires_at, 'last_used_at' => $r->last_used_at === null ? null : (string) $r->last_used_at,
            'revoked_at' => $r->revoked_at === null ? null : (string) $r->revoked_at],
            DB::select('select id, name, prefix, scopes::text as scopes, created_at, expires_at, last_used_at, revoked_at from api_keys where user_id = ? order by created_at desc, id desc limit 50', [$userId]));
    }

    public function usage(string $userId): array
    {
        $out = [];
        foreach (DB::select("select u.key_id, sum(u.calls)::int as total, coalesce(sum(u.calls) filter (where u.day >= (now() at time zone 'Asia/Dhaka')::date - 6), 0)::int as last7
            from api_key_usage u join api_keys k on k.id = u.key_id where k.user_id = ? group by u.key_id", [$userId]) as $r) {
            $out[trim((string) $r->key_id)] = ['total' => (int) $r->total, 'last7' => (int) $r->last7];
        }

        return $out;
    }

    public function resolve(string $hash): ?array
    {
        $j = DB::selectOne('select api_key_resolve(?) as r', [$hash])->r;
        if ($j === null) {
            return null;
        }
        $a = (array) json_decode((string) $j, true);

        return ['key_id' => (string) $a['key_id'], 'tenant_id' => (string) $a['tenant_id'], 'user_id' => (string) $a['user_id'], 'scopes' => array_values(array_map('strval', (array) $a['scopes']))];
    }
}
