<?php

declare(strict_types=1);

namespace App\Leads;

use App\Actions\Tenancy\CreateWorkspace;
use Illuminate\Support\Facades\DB;

/** Postgres implementation. The tenant context must be set by the caller (RLS confines every statement). */
final class PgLeadStore implements LeadStore
{
    public function createJob(string $tenantId, string $userId, string $name, string $inputType, array $items, array $options): string
    {
        return DB::transaction(function () use ($tenantId, $userId, $name, $inputType, $items, $options): string {
            $id = CreateWorkspace::newId();
            DB::insert('insert into lead_research_jobs (id, tenant_id, user_id, name, input_type, total, options) values (?, ?, ?, ?, ?, ?, ?::jsonb)',
                [$id, $tenantId, $userId, mb_substr($name, 0, 120), $inputType, count($items), json_encode($options, JSON_THROW_ON_ERROR)]);
            foreach ($items as $i) {
                DB::insert('insert into lead_research_items (id, tenant_id, job_id, url, domain) values (?, ?, ?, ?, ?)',
                    [CreateWorkspace::newId(), $tenantId, $id, $i['url'], $i['domain']]);
            }

            return $id;
        });
    }

    public function itemIds(string $jobId): array
    {
        return array_map(fn ($r) => trim($r->id), DB::select('select id from lead_research_items where job_id = ? order by id', [$jobId]));
    }

    public function claimItem(string $itemId): ?array
    {
        $rows = DB::select("update lead_research_items i set status = 'running', attempts = i.attempts + 1, started_at = now()
            from lead_research_jobs j
            where i.id = ? and i.status = 'queued' and j.id = i.job_id and j.status <> 'cancelled'
            returning i.id, i.job_id, i.url, i.domain, i.tenant_id, j.user_id, j.options", [$itemId]);
        if ($rows === []) {
            return null;
        }
        $r = $rows[0];
        DB::update("update lead_research_jobs set status = 'running', started_at = coalesce(started_at, now()) where id = ? and status = 'queued'", [$r->job_id]);

        return ['id' => trim($r->id), 'job_id' => trim($r->job_id), 'url' => $r->url, 'domain' => $r->domain, 'tenant_id' => trim($r->tenant_id), 'user_id' => trim($r->user_id),
            'options' => json_decode((string) $r->options, true) ?: []];
    }

    public function requeueItem(string $itemId): void
    {
        DB::update("update lead_research_items set status = 'queued' where id = ? and status = 'running'", [$itemId]);
    }

    public function finishItem(string $itemId, string $status, string $code, ?string $companyId): void
    {
        DB::update('update lead_research_items set status = ?, result_code = ?, company_id = ?, finished_at = now() where id = ?', [$status, mb_substr($code, 0, 40), $companyId, $itemId]);
    }

    public function refreshJob(string $jobId): void
    {
        DB::update("update lead_research_jobs set status = 'done', finished_at = now() where id = ? and status in ('queued', 'running')
            and not exists (select 1 from lead_research_items where job_id = ? and status in ('queued', 'running'))", [$jobId, $jobId]);
    }

    public function findCompany(string $tenantId, string $domain): ?array
    {
        $r = DB::selectOne('select id, name, score from companies where tenant_id = ? and domain = ? and deleted_at is null', [$tenantId, $domain]);

        return $r === null ? null : ['id' => trim($r->id), 'name' => $r->name, 'score' => $r->score === null ? null : (int) $r->score];
    }

    public function createCompany(string $tenantId, array $d): string
    {
        $id = CreateWorkspace::newId();
        DB::insert('insert into companies (id, tenant_id, name, domain, website, description, address, analysis, score, analyzed_at) values (?, ?, ?, ?, ?, ?, ?, ?::jsonb, ?, case when ? then now() end)',
            [$id, $tenantId, $d['name'], $d['domain'], $d['website'], $d['description'], $d['address'], json_encode($d['analysis'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), $d['score'], (bool) ($d['analyzed'] ?? true)]);

        return $id;
    }

    public function updateCompany(string $companyId, array $d): void
    {
        DB::update('update companies set website = ?, description = coalesce(?, description), address = coalesce(?, address), analysis = ?::jsonb, score = ?, analyzed_at = now(), updated_at = now() where id = ?',
            [$d['website'], $d['description'], $d['address'], json_encode($d['analysis'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), $d['score'], $companyId]);
    }

    public function ensureLead(string $tenantId, string $companyId, int $score, string $source, ?string $jobId, ?string $ownerId): bool
    {
        return DB::affectingStatement('insert into leads (id, tenant_id, company_id, score, source, job_id, owner_id) values (?, ?, ?, ?, ?, ?, ?)
            on conflict (tenant_id, company_id) where deleted_at is null do nothing', [CreateWorkspace::newId(), $tenantId, $companyId, $score, $source, $jobId, $ownerId]) === 1;
    }

    public function updateLeadScore(string $tenantId, string $companyId, int $score): void
    {
        DB::update('update leads set score = ?, updated_at = now() where tenant_id = ? and company_id = ? and deleted_at is null', [$score, $tenantId, $companyId]);
    }

    public function addContact(string $tenantId, string $companyId, array $c): bool
    {
        return DB::affectingStatement('insert into contacts (id, tenant_id, company_id, email, email_status, phone_e164, phone_type, source_url) values (?, ?, ?, ?, ?, ?, ?, ?)
            on conflict do nothing', [CreateWorkspace::newId(), $tenantId, $companyId, $c['email'] ?? null, $c['email_status'] ?? 'unverified', $c['phone'] ?? null, $c['phone_type'] ?? null, ($c['source_url'] ?? '') ?: null]) === 1;
    }

    public function contactCount(string $companyId): int
    {
        return (int) DB::scalar('select count(*) from contacts where company_id = ?', [$companyId]);
    }
}
