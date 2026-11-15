<?php

declare(strict_types=1);

namespace App\Companion;

use Illuminate\Support\Facades\DB;

final class PgModeRepo implements ModeRepo
{
    public function get(string $tenantId, string $userId): array
    {
        $r = DB::table('user_modes')->where('tenant_id', $tenantId)->where('user_id', $userId)->first(['mode', 'character_id']);

        return $r === null ? ['mode' => 'agent', 'character_id' => null] : ['mode' => (string) $r->mode, 'character_id' => $r->character_id === null ? null : trim((string) $r->character_id)];
    }

    public function set(string $tenantId, string $userId, string $mode, ?string $characterId): string
    {
        return (string) DB::selectOne('select user_mode_set(?, ?, ?, ?) as r', [$tenantId, $userId, $mode, $characterId])->r;
    }
}
