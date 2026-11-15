<?php

declare(strict_types=1);

namespace App\Proposals;

use App\Actions\Tenancy\CreateWorkspace;
use Illuminate\Support\Facades\DB;

/** Raw SQL on purpose: every statement runs under the tenant's RLS and is executed against real Postgres in tests/db. */
final class PgProposalRepo implements ProposalRepo
{
    public function template(string $id): ?array
    {
        $r = DB::selectOne('select id, kind, language, name, sections from proposal_templates where id = ? and deleted_at is null', [$id]);

        return $r === null ? null : ['id' => trim($r->id), 'kind' => $r->kind, 'language' => $r->language, 'name' => $r->name, 'sections' => json_decode((string) $r->sections, true) ?: []];
    }

    public function brand(): ?array
    {
        $r = DB::selectOne('select id, name, primary_color, accent_color, logo_b64, tone, email, phone, website, address from brand_kits where is_default order by created_at limit 1');

        return $r === null ? null : (array) $r + [];
    }

    public function leadSummary(string $leadId): ?array
    {
        $r = DB::selectOne('select c.name, c.domain, c.analysis from leads l join companies c on c.id = l.company_id where l.id = ? and l.deleted_at is null', [$leadId]);

        return $r === null ? null : ['name' => $r->name, 'domain' => $r->domain, 'analysis' => json_decode((string) $r->analysis, true) ?: []];
    }

    public function create(string $tenantId, string $userId, ?string $leadId, ?string $templateId, ?string $brandId, string $title, string $language, array $data): string
    {
        $id = CreateWorkspace::newId();
        DB::insert('insert into proposals (id, tenant_id, lead_id, template_id, brand_kit_id, title, language, data, created_by) values (?, ?, ?, ?, ?, ?, ?, ?::jsonb, ?)',
            [$id, $tenantId, $leadId, $templateId, $brandId, $title, $language, json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), $userId]);

        return $id;
    }

    public function find(string $id): ?array
    {
        $r = DB::selectOne('select id, tenant_id, lead_id, title, language, status, data from proposals where id = ? and deleted_at is null', [$id]);

        return $r === null ? null : ['id' => trim($r->id), 'tenant_id' => trim($r->tenant_id), 'lead_id' => $r->lead_id === null ? null : trim($r->lead_id), 'title' => $r->title, 'language' => $r->language, 'status' => $r->status, 'data' => json_decode((string) $r->data, true) ?: []];
    }

    public function update(string $id, string $title, string $language, array $data): void
    {
        DB::update('update proposals set title = ?, language = ?, data = ?::jsonb, updated_at = now() where id = ? and deleted_at is null',
            [$title, $language, json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), $id]);
    }

    public function setStatus(string $id, string $status): void
    {
        DB::update('update proposals set status = ?, updated_at = now() where id = ? and deleted_at is null', [$status, $id]);
    }

    public function portfolioByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $rows = DB::select('select id, title, url, description from portfolio_items where deleted_at is null and id = any(?::text[])', ['{'.implode(',', array_map(fn ($i) => '"'.$i.'"', $ids)).'}']);
        $by = [];
        foreach ($rows as $r) {
            $by[trim($r->id)] = ['id' => trim($r->id), 'title' => $r->title, 'url' => $r->url, 'description' => $r->description];
        }

        return array_values(array_filter(array_map(fn ($i) => $by[$i] ?? null, $ids)));
    }
}
