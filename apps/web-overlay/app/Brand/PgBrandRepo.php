<?php

declare(strict_types=1);

namespace App\Brand;

use Illuminate\Support\Facades\DB;

final class PgBrandRepo implements BrandRepo
{
    public function get(string $tenantId): ?array
    {
        $r = DB::selectOne('select enabled, brand_name, color, support_email, hide_powered_by from tenant_brands where tenant_id = ?', [$tenantId]);

        return $r === null ? null : ['enabled' => (bool) $r->enabled, 'brand_name' => $r->brand_name, 'color' => $r->color === null ? null : trim((string) $r->color), 'support_email' => $r->support_email, 'hide_powered_by' => (bool) $r->hide_powered_by];
    }

    public function save(string $tenantId, string $userId, ?string $name, ?string $color, ?string $email, bool $hide): string
    {
        return (string) DB::selectOne('select brand_save(?, ?, ?, ?, ?, ?) as r', [$tenantId, $userId, $name, $color, $email, $hide ? 'true' : 'false'])->r;
    }
}
