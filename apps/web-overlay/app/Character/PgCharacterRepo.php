<?php

declare(strict_types=1);

namespace App\Character;

use App\Actions\Tenancy\CreateWorkspace;
use Illuminate\Support\Facades\DB;

final class PgCharacterRepo implements CharacterRepo
{
    private const COLS = ['id', 'name', 'kind', 'personality', 'speaking_style', 'language', 'voice', 'avatar_style', 'greeting', 'created_by'];

    public function all(string $tenantId): array
    {
        return DB::table('characters')->where('tenant_id', $tenantId)->whereNull('deleted_at')->orderBy('created_at')->get(self::COLS)
            ->map(fn ($r) => $this->row($r))->all();
    }

    public function find(string $tenantId, string $id): ?array
    {
        $r = DB::table('characters')->where('tenant_id', $tenantId)->where('id', $id)->whereNull('deleted_at')->first(self::COLS);

        return $r === null ? null : $this->row($r);
    }

    public function create(string $tenantId, string $userId, array $data): string
    {
        $id = CreateWorkspace::newId();
        DB::table('characters')->insert(['id' => $id, 'tenant_id' => $tenantId, 'created_by' => $userId] + $data);

        return $id;
    }

    public function update(string $tenantId, string $userId, string $id, array $data): bool
    {
        return DB::table('characters')->where('tenant_id', $tenantId)->where('id', $id)->where('created_by', $userId)->whereNull('deleted_at')
            ->update($data + ['updated_at' => now()]) === 1;
    }

    public function delete(string $tenantId, string $userId, string $id): bool
    {
        return DB::table('characters')->where('tenant_id', $tenantId)->where('id', $id)->where('created_by', $userId)->whereNull('deleted_at')
            ->update(['deleted_at' => now()]) === 1;
    }

    public function count(string $tenantId): int
    {
        return DB::table('characters')->where('tenant_id', $tenantId)->whereNull('deleted_at')->count();
    }

    /** @return array<string, mixed> */
    private function row(object $r): array
    {
        return ['id' => trim((string) $r->id), 'name' => $r->name, 'kind' => $r->kind, 'personality' => $r->personality, 'speaking_style' => $r->speaking_style,
            'language' => $r->language, 'voice' => $r->voice, 'avatar_style' => $r->avatar_style, 'greeting' => $r->greeting, 'created_by' => trim((string) $r->created_by)];
    }
}
