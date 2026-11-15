<?php

declare(strict_types=1);

namespace App\Crm;

use App\Actions\Tenancy\CreateWorkspace;
use Illuminate\Support\Facades\DB;

/** Raw SQL on purpose: every statement runs under the tenant's RLS and is executed against real Postgres in tests/db. */
final class PgCrmStore implements CrmStore
{
    public function lead(string $leadId): ?array
    {
        $r = DB::selectOne('select id, stage, tenant_id from leads where id = ? and deleted_at is null', [$leadId]);

        return $r === null ? null : ['id' => trim($r->id), 'stage' => $r->stage, 'tenant_id' => trim($r->tenant_id)];
    }

    public function moveStage(string $leadId, string $stage, string $userId): ?string
    {
        $r = DB::selectOne('select lead_move_stage(?, ?, ?) as old_stage', [$leadId, $stage, $userId]);

        return $r?->old_stage;
    }

    public function addActivity(string $tenantId, string $leadId, ?string $userId, string $kind, string $body, array $meta = []): void
    {
        DB::insert('insert into lead_activities (id, tenant_id, lead_id, user_id, kind, body, meta) values (?, ?, ?, ?, ?, ?, ?::jsonb)',
            [CreateWorkspace::newId(), $tenantId, $leadId, $userId, $kind, $body, json_encode((object) $meta, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)]);
    }

    public function addTask(string $tenantId, string $leadId, string $userId, string $title, ?string $dueAt, ?string $assigneeId): string
    {
        $id = CreateWorkspace::newId();
        DB::insert('insert into crm_tasks (id, tenant_id, lead_id, title, due_at, assignee_id, created_by) values (?, ?, ?, ?, ?, ?, ?)',
            [$id, $tenantId, $leadId, $title, $dueAt, $assigneeId, $userId]);

        return $id;
    }

    public function completeTask(string $taskId): ?array
    {
        $r = DB::selectOne('update crm_tasks set done_at = now() where id = ? and done_at is null returning lead_id, tenant_id, title', [$taskId]);

        return $r === null ? null : ['lead_id' => trim($r->lead_id), 'tenant_id' => trim($r->tenant_id), 'title' => $r->title];
    }

    public function setTags(string $leadId, array $tags): void
    {
        $literal = '{'.implode(',', array_map(fn (string $t) => '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $t).'"', $tags)).'}';
        DB::update('update leads set tags = ?::text[], updated_at = now() where id = ? and deleted_at is null', [$literal, $leadId]);
    }

    public function setFollowUp(string $leadId, ?string $at): void
    {
        DB::update('update leads set next_follow_up_at = ?, updated_at = now() where id = ? and deleted_at is null', [$at, $leadId]);
    }

    public function leadForCompany(string $companyId): ?string
    {
        $r = DB::selectOne('select id from leads where company_id = ? and deleted_at is null', [$companyId]);

        return $r === null ? null : trim($r->id);
    }
}
