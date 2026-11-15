<?php

declare(strict_types=1);

namespace App\Office;

use Illuminate\Support\Facades\DB;

final class PgOfficeRepo implements OfficeRepo
{
    public function list(string $userId, int $limit = 100): array
    {
        return array_map(fn ($r) => ['id' => trim((string) $r->id), 'kind' => (string) $r->kind, 'title' => (string) $r->title, 'request' => (string) $r->request, 'created_at' => (string) $r->created_at],
            DB::select('select id, kind, title, request, created_at from generated_files where user_id = ? order by created_at desc, id limit ?', [$userId, max(1, min(100, $limit))]));
    }

    public function find(string $userId, string $id): ?array
    {
        $r = DB::selectOne('select id, kind, title, spec from generated_files where user_id = ? and id = ?', [$userId, $id]);
        if ($r === null) {
            return null;
        }
        $spec = json_decode((string) $r->spec, true);

        return is_array($spec) ? ['id' => trim((string) $r->id), 'kind' => (string) $r->kind, 'title' => (string) $r->title, 'spec' => $spec] : null;
    }

    public function count(string $userId): int
    {
        return (int) DB::selectOne('select count(*) as n from generated_files where user_id = ?', [$userId])->n;
    }

    public function save(string $tenantId, string $userId, string $id, string $kind, string $title, string $request, array $spec): string
    {
        return (string) DB::selectOne('select genfile_save(?, ?, ?, ?, ?, ?, ?::jsonb) as r',
            [$id, $tenantId, $userId, $kind, $title, $request, json_encode($spec, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)])->r;
    }

    public function delete(string $tenantId, string $userId, string $id): string
    {
        return (string) DB::selectOne('select genfile_delete(?, ?, ?) as r', [$id, $tenantId, $userId])->r;
    }
}
