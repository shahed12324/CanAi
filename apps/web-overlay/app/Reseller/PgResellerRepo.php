<?php

declare(strict_types=1);

namespace App\Reseller;

use Illuminate\Support\Facades\DB;

final class PgResellerRepo implements ResellerRepo
{
    public function join(string $tenantId, string $userId, string $code): string
    {
        return (string) DB::selectOne('select reseller_join(?, ?, ?) as r', [$tenantId, $userId, $code])->r;
    }

    public function of(string $tenantId, string $userId): ?string
    {
        $r = DB::selectOne('select reseller_of(?, ?) as r', [$tenantId, $userId])->r;

        return $r === null ? null : (string) $r;
    }

    public function me(string $tenantId, string $userId): ?array
    {
        $r = DB::selectOne('select reseller_me(?, ?) as r', [$tenantId, $userId])->r;

        return $r === null ? null : json_decode((string) $r, true, 512, JSON_THROW_ON_ERROR);
    }

    public function report(string $tenantId, string $userId): ?array
    {
        $r = DB::selectOne('select reseller_report(?, ?) as r', [$tenantId, $userId])->r;

        return $r === null ? null : json_decode((string) $r, true, 512, JSON_THROW_ON_ERROR);
    }
}
