<?php

declare(strict_types=1);

namespace App\License;

use Illuminate\Support\Facades\DB;

final class PgLicenseRepo implements LicenseRepo
{
    public function seatsLeft(string $tenantId): ?int
    {
        $n = DB::selectOne('select license_seats_left(?) as n', [$tenantId])->n;

        return $n === null ? null : (int) $n;
    }

    public function get(string $tenantId): ?array
    {
        $r = DB::selectOne("select seats, expires_at, note, (select count(*) from tenant_users where tenant_id = licenses.tenant_id and status = 'active') as used from licenses where tenant_id = ?", [$tenantId]);

        return $r === null ? null : ['seats' => (int) $r->seats, 'expires_at' => (string) $r->expires_at, 'note' => (string) $r->note, 'used' => (int) $r->used];
    }
}
