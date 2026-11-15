<?php

declare(strict_types=1);

namespace App\Clients;

use App\Actions\Tenancy\CreateWorkspace;
use Illuminate\Support\Facades\DB;

final class PgClientRepo implements ClientRepo
{
    public function clients(): array
    {
        $rows = DB::select("select c.id, c.name, c.notes, k.primary_color, k.accent_color, (k.logo_b64 is not null) as has_logo, k.email, k.phone, k.website, k.address
            from clients c left join brand_kits k on k.id = c.brand_kit_id and k.tenant_id = c.tenant_id where c.status = 'active' order by lower(c.name) limit 200");

        return array_map(fn ($r) => ['id' => trim((string) $r->id), 'name' => (string) $r->name, 'notes' => (string) $r->notes, 'primary_color' => $r->primary_color === null ? null : trim((string) $r->primary_color), 'accent_color' => $r->accent_color === null ? null : trim((string) $r->accent_color),
            'has_logo' => (bool) $r->has_logo, 'email' => $r->email, 'phone' => $r->phone, 'website' => $r->website, 'address' => $r->address], $rows);
    }

    public function accounts(): array
    {
        $out = [];
        foreach (DB::select('select id, name, currency, status, client_id from ads_accounts order by lower(name) limit 200') as $r) {
            $out[] = ['kind' => 'meta', 'id' => trim((string) $r->id), 'name' => (string) $r->name, 'detail' => 'Meta Ads · '.trim((string) $r->currency), 'client_id' => $r->client_id === null ? null : trim((string) $r->client_id)];
        }
        foreach (DB::select('select id, name, currency, status, client_id from gads_accounts order by lower(name) limit 200') as $r) {
            $out[] = ['kind' => 'google', 'id' => trim((string) $r->id), 'name' => (string) $r->name, 'detail' => 'Google Ads · '.trim((string) $r->currency), 'client_id' => $r->client_id === null ? null : trim((string) $r->client_id)];
        }
        foreach (DB::select('select id, name, platform, client_id from social_accounts order by lower(name) limit 200') as $r) {
            $out[] = ['kind' => 'social', 'id' => trim((string) $r->id), 'name' => (string) $r->name, 'detail' => 'Facebook Page', 'client_id' => $r->client_id === null ? null : trim((string) $r->client_id)];
        }

        return $out;
    }

    public function save(string $tenantId, string $userId, ?string $clientId, array $f): string
    {
        $r = DB::selectOne('select client_save(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?) as r', [$clientId ?? CreateWorkspace::newId(), CreateWorkspace::newId(), $tenantId, $userId, $clientId === null, $f['name'], $f['primary'], $f['accent'],
            $f['email'] ?? '', $f['phone'] ?? '', $f['website'] ?? '', $f['address'] ?? '', $f['notes'] ?? '', $f['logo'] ?? null, (bool) ($f['set_logo'] ?? false)]);

        return (string) $r->r;
    }

    public function archive(string $tenantId, string $userId, string $clientId): string
    {
        return (string) DB::selectOne('select client_archive(?,?,?) as r', [$tenantId, $userId, $clientId])->r;
    }

    public function assign(string $tenantId, string $userId, string $kind, string $accountId, ?string $clientId): string
    {
        return (string) DB::selectOne('select client_assign(?,?,?,?,?) as r', [$tenantId, $userId, $kind, $accountId, $clientId])->r;
    }
}
