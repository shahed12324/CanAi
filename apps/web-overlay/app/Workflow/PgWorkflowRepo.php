<?php

declare(strict_types=1);

namespace App\Workflow;

use Illuminate\Support\Facades\DB;

/** Reads are tenant-scoped by RLS and filtered to the person here; every write goes through a function. */
final class PgWorkflowRepo implements WorkflowRepo
{
    private static function wf(object $r): array
    {
        return ['id' => trim((string) $r->id), 'name' => (string) $r->name, 'description' => (string) $r->description, 'category' => (string) $r->category,
            'definition' => (array) json_decode((string) $r->definition, true), 'version' => (int) $r->version, 'enabled' => (bool) $r->enabled,
            'schedule_time' => $r->schedule_time === null ? null : substr((string) $r->schedule_time, 0, 5), 'max_credits' => (float) $r->max_credits,
            'paused' => (bool) $r->paused, 'model_id' => $r->model_id === null ? null : trim((string) $r->model_id), 'template_key' => $r->template_key === null ? null : (string) $r->template_key];
    }

    public function workflows(string $userId): array
    {
        return array_map(self::wf(...), DB::select('select id, name, description, category, definition::text as definition, version, enabled, schedule_time, max_credits, paused, model_id, template_key from wf_workflows where user_id = ? order by created_at desc', [$userId]));
    }

    public function workflow(string $userId, string $id): ?array
    {
        $r = DB::selectOne('select id, name, description, category, definition::text as definition, version, enabled, schedule_time, max_credits, paused, model_id, template_key from wf_workflows where user_id = ? and id = ?', [$userId, $id]);

        return $r === null ? null : self::wf($r);
    }

    public function save(string $tenantId, string $userId, string $id, string $name, string $desc, string $category, array $definition, bool $enabled, ?string $schedule, float $maxCredits, bool $isNew): string
    {
        return (string) DB::selectOne('select wf_save(?, ?, ?, ?, ?, ?, ?::jsonb, ?::boolean, ?::time, ?::numeric, ?::boolean) as r', [
            $id, $tenantId, $userId, $name, $desc, $category, json_encode($definition, JSON_UNESCAPED_UNICODE), $enabled ? 'true' : 'false', $schedule, (string) $maxCredits, $isNew ? 'true' : 'false'])->r;
    }

    private static function b(bool $v): string
    {
        return $v ? 'true' : 'false';
    }

    public function rename(string $tenantId, string $userId, string $id, string $name, string $desc): string
    {
        return (string) DB::selectOne('select wf_rename(?, ?, ?, ?, ?) as r', [$id, $tenantId, $userId, $name, $desc])->r;
    }

    public function markTemplate(string $tenantId, string $userId, string $id, string $key): string
    {
        return (string) DB::selectOne('select wf_mark_template(?, ?, ?, ?) as r', [$id, $tenantId, $userId, $key])->r;
    }

    public function setMeta(string $tenantId, string $userId, string $id, string $category, ?string $schedule, float $maxCredits, ?string $modelId): string
    {
        return (string) DB::selectOne('select wf_set_meta(?, ?, ?, ?, ?::time, ?::numeric, ?) as r', [$id, $tenantId, $userId, $category, $schedule, (string) $maxCredits, $modelId])->r;
    }

    public function setDefinition(string $tenantId, string $userId, string $id, array $definition): string
    {
        return (string) DB::selectOne('select wf_set_definition(?, ?, ?, ?::jsonb) as r', [$id, $tenantId, $userId, json_encode($definition, JSON_UNESCAPED_UNICODE)])->r;
    }

    public function setState(string $tenantId, string $userId, string $id, ?bool $enabled, ?bool $paused): string
    {
        return (string) DB::selectOne('select wf_set_state(?, ?, ?, ?::boolean, ?::boolean) as r', [$id, $tenantId, $userId, $enabled === null ? null : self::b($enabled), $paused === null ? null : self::b($paused)])->r;
    }

    public function duplicate(string $tenantId, string $userId, string $sourceId, string $newId, string $name): string
    {
        return (string) DB::selectOne('select wf_duplicate(?, ?, ?, ?, ?) as r', [$sourceId, $newId, $tenantId, $userId, $name])->r;
    }

    public function createTestRun(string $tenantId, string $userId, string $runId, string $workflowId, array $vars): string
    {
        return (string) DB::selectOne('select wf_test_run_create(?, ?, ?, ?, ?::jsonb) as r', [$runId, $tenantId, $userId, $workflowId, json_encode((object) $vars, JSON_UNESCAPED_UNICODE)])->r;
    }

    public function runsFor(string $userId, string $workflowId, int $limit = 20): array
    {
        $rows = DB::select('select r.id, r.workflow_id, w.name, r.status, r.trigger, r.spent, r.max_credits, r.error, r.created_at, r.finished_at from wf_runs r join wf_workflows w on w.id = r.workflow_id and w.tenant_id = r.tenant_id where r.user_id = ? and r.workflow_id = ? order by r.created_at desc limit ?', [$userId, $workflowId, max(1, min(100, $limit))]);

        return array_map(fn ($r) => ['id' => trim((string) $r->id), 'workflow_id' => trim((string) $r->workflow_id), 'workflow_name' => (string) $r->name, 'status' => (string) $r->status, 'trigger' => (string) $r->trigger,
            'spent' => (float) $r->spent, 'max_credits' => (float) $r->max_credits, 'error' => $r->error === null ? null : (string) $r->error, 'created_at' => (string) $r->created_at, 'finished_at' => $r->finished_at === null ? null : (string) $r->finished_at], $rows);
    }

    public function delete(string $tenantId, string $userId, string $id): string
    {
        return (string) DB::selectOne('select wf_delete(?, ?, ?) as r', [$id, $tenantId, $userId])->r;
    }

    public function createRun(string $tenantId, string $userId, string $runId, string $workflowId, array $vars): string
    {
        return (string) DB::selectOne('select wf_run_create(?, ?, ?, ?, ?::jsonb) as r', [$runId, $tenantId, $userId, $workflowId, json_encode((object) $vars, JSON_UNESCAPED_UNICODE)])->r;
    }

    public function runs(string $userId, int $limit = 20): array
    {
        $rows = DB::select('select r.id, r.workflow_id, w.name, r.status, r.trigger, r.spent, r.max_credits, r.error, r.created_at, r.finished_at from wf_runs r join wf_workflows w on w.id = r.workflow_id and w.tenant_id = r.tenant_id where r.user_id = ? order by r.created_at desc limit ?', [$userId, max(1, min(100, $limit))]);

        return array_map(fn ($r) => ['id' => trim((string) $r->id), 'workflow_id' => trim((string) $r->workflow_id), 'workflow_name' => (string) $r->name, 'status' => (string) $r->status, 'trigger' => (string) $r->trigger,
            'spent' => (float) $r->spent, 'max_credits' => (float) $r->max_credits, 'error' => $r->error === null ? null : (string) $r->error, 'created_at' => (string) $r->created_at, 'finished_at' => $r->finished_at === null ? null : (string) $r->finished_at], $rows);
    }

    public function steps(string $userId, string $runId): array
    {
        $rows = DB::select('select s.seq, s.node_id, s.node_type, s.status, s.output, s.credits from wf_steps s join wf_runs r on r.id = s.run_id and r.tenant_id = s.tenant_id where r.user_id = ? and s.run_id = ? order by s.seq', [$userId, $runId]);

        return array_map(fn ($r) => ['seq' => (int) $r->seq, 'node_id' => (string) $r->node_id, 'node_type' => (string) $r->node_type, 'status' => (string) $r->status, 'output' => (string) $r->output, 'credits' => (float) $r->credits], $rows);
    }

    public function pendingApprovals(string $userId): array
    {
        $out = [];
        foreach (DB::select("select r.id, w.name, r.definition::text as definition, r.vars::text as vars, r.current_node from wf_runs r join wf_workflows w on w.id = r.workflow_id and w.tenant_id = r.tenant_id where r.user_id = ? and r.status = 'waiting_approval' order by r.created_at", [$userId]) as $r) {
            $def = (array) json_decode((string) $r->definition, true);
            $node = $def['nodes'][$r->current_node] ?? [];
            $out[] = ['run_id' => trim((string) $r->id), 'workflow_name' => (string) $r->name, 'message' => Vars::render((string) ($node['message'] ?? ''), (array) json_decode((string) $r->vars, true))];
        }

        return $out;
    }

    public function decide(string $tenantId, string $userId, string $runId, bool $approve): string
    {
        return (string) DB::selectOne('select wf_run_decide(?, ?, ?, ?::boolean) as r', [$runId, $tenantId, $userId, $approve ? 'true' : 'false'])->r;
    }

    public function cancel(string $tenantId, string $userId, string $runId): string
    {
        return (string) DB::selectOne('select wf_run_cancel(?, ?, ?) as r', [$runId, $tenantId, $userId])->r;
    }

    public function run(string $runId): ?array
    {
        $r = DB::selectOne('select r.id, r.user_id, r.status, r.trigger, w.model_id, r.definition::text as definition, r.vars::text as vars, r.current_node, r.spent, r.max_credits, w.name,
            (select count(*) from wf_steps s where s.run_id = r.id) as steps, (select u.timezone from users u where u.id = r.user_id) as tz
            from wf_runs r join wf_workflows w on w.id = r.workflow_id and w.tenant_id = r.tenant_id where r.id = ?', [$runId]);
        if ($r === null) {
            return null;
        }

        return ['id' => trim((string) $r->id), 'user_id' => trim((string) $r->user_id), 'status' => (string) $r->status, 'definition' => (array) json_decode((string) $r->definition, true),
            'vars' => (array) json_decode((string) $r->vars, true), 'current_node' => $r->current_node === null ? null : (string) $r->current_node, 'spent' => (float) $r->spent, 'max_credits' => (float) $r->max_credits,
            'steps' => (int) $r->steps, 'timezone' => (string) ($r->tz ?: 'Asia/Dhaka'), 'workflow_name' => (string) $r->name,
            'trigger' => (string) $r->trigger, 'model_id' => $r->model_id === null ? null : trim((string) $r->model_id)];
    }

    public function record(string $tenantId, string $runId, string $node, string $type, string $status, string $output, string $credits, ?array $vars, ?string $next): string
    {
        return (string) DB::selectOne('select wf_step_record(?, ?, ?, ?, ?, ?, ?::numeric, ?::jsonb, ?) as r', [$runId, $tenantId, $node, $type, $status, $output, $credits,
            $vars === null ? null : json_encode((object) $vars, JSON_UNESCAPED_UNICODE), $next])->r;
    }

    public function delay(string $tenantId, string $runId, string $node, string $next, int $minutes, ?array $vars): string
    {
        return (string) DB::selectOne('select wf_step_delay(?, ?, ?, ?, ?, ?::jsonb) as r', [$runId, $tenantId, $node, $next, $minutes, $vars === null ? null : json_encode((object) $vars, JSON_UNESCAPED_UNICODE)])->r;
    }

    public function end(string $tenantId, string $runId, string $status, ?string $error): string
    {
        return (string) DB::selectOne('select wf_run_end(?, ?, ?, ?) as r', [$runId, $tenantId, $status, $error === null ? null : mb_substr($error, 0, 300)])->r;
    }
}
