<?php

declare(strict_types=1);

namespace App\Companion;

interface ModeRepo
{
    /** No row means 'agent' (nothing changes until the person chooses).  @return array{mode: string, character_id: ?string} */
    public function get(string $tenantId, string $userId): array;

    /** @return 'ok'|'bad_input'|'forbidden' */
    public function set(string $tenantId, string $userId, string $mode, ?string $characterId): string;
}
