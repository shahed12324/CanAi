<?php

declare(strict_types=1);

namespace App\Agents;

use Illuminate\Support\Facades\DB;

/** Reads are tenant-scoped by RLS and filtered to the person here; every write goes through a database function. */
final class PgAgentRepo implements AgentRepo
{
    private const COLS = 'id, name, description, system_prompt, model_id, temperature, max_tokens, tools, use_knowledge, use_memory, permissions::text as permissions, max_credits, enabled, version, created_at, updated_at';

    private static function arr(object $r): array
    {
        $tools = trim((string) $r->tools, '{}');

        return ['id' => trim((string) $r->id), 'name' => (string) $r->name, 'description' => (string) $r->description, 'system_prompt' => (string) $r->system_prompt,
            'model_id' => $r->model_id === null ? null : trim((string) $r->model_id), 'temperature' => (float) $r->temperature, 'max_tokens' => (int) $r->max_tokens,
            'tools' => $tools === '' ? [] : array_map(fn ($t) => trim($t, '"'), explode(',', $tools)), 'use_knowledge' => (bool) $r->use_knowledge, 'use_memory' => (bool) $r->use_memory,
            'permissions' => (array) json_decode((string) $r->permissions, true), 'max_credits' => (float) $r->max_credits, 'enabled' => (bool) $r->enabled, 'version' => (int) $r->version,
            'created_at' => (string) $r->created_at, 'updated_at' => (string) $r->updated_at];
    }

    public function agents(string $userId): array
    {
        return array_map(self::arr(...), DB::select('select '.self::COLS.' from wf_agents where user_id = ? order by lower(name)', [$userId]));
    }

    public function agent(string $userId, string $id): ?array
    {
        $r = DB::selectOne('select '.self::COLS.' from wf_agents where user_id = ? and id = ?', [$userId, $id]);

        return $r === null ? null : self::arr($r);
    }

    public function save(string $tenantId, string $userId, string $id, bool $isNew, array $d): string
    {
        $tools = '{'.implode(',', array_map(fn ($t) => '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], (string) $t).'"', $d['tools'])).'}';

        return (string) DB::selectOne('select wf_agent_save(?, ?, ?, ?::boolean, ?, ?, ?, ?, ?::numeric, ?, ?::text[], ?::boolean, ?::boolean, ?::jsonb, ?::numeric, ?::boolean) as r', [
            $id, $tenantId, $userId, $isNew ? 'true' : 'false', $d['name'], $d['description'], $d['system_prompt'], $d['model_id'], (string) $d['temperature'], $d['max_tokens'], $tools,
            $d['use_knowledge'] ? 'true' : 'false', $d['use_memory'] ? 'true' : 'false', json_encode((object) $d['permissions']), (string) $d['max_credits'], $d['enabled'] ? 'true' : 'false'])->r;
    }

    public function delete(string $tenantId, string $userId, string $id): string
    {
        return (string) DB::selectOne('select wf_agent_delete(?, ?, ?) as r', [$id, $tenantId, $userId])->r;
    }

    public function restore(string $tenantId, string $userId, string $id, int $version): string
    {
        return (string) DB::selectOne('select wf_agent_restore(?, ?, ?, ?) as r', [$id, $tenantId, $userId, $version])->r;
    }

    public function versions(string $userId, string $id): array
    {
        return array_map(fn ($r) => ['version' => (int) $r->version, 'changed_at' => (string) $r->created_at, 'snapshot' => (array) json_decode((string) $r->snapshot, true)],
            DB::select('select v.version, v.created_at, v.snapshot::text as snapshot from wf_agent_versions v join wf_agents a on a.id = v.agent_id and a.tenant_id = v.tenant_id where a.user_id = ? and a.id = ? order by v.version desc', [$userId, $id]));
    }

    public function recordRun(string $tenantId, string $userId, string $runId, string $agentId, string $kind, string $status, string $input, string $output, string $credits, int $in, int $out, string $model): string
    {
        return (string) DB::selectOne('select wf_agent_run_record(?, ?, ?, ?, ?, ?, ?, ?, ?::numeric, ?, ?, ?) as r', [$runId, $tenantId, $userId, $agentId, $kind, $status, $input, $output, $credits, $in, $out, $model])->r;
    }

    public function runs(string $userId, string $agentId, int $limit = 20): array
    {
        return array_map(fn ($r) => (array) $r, DB::select('select r.id, r.agent_version, r.kind, r.status, r.input, r.output, r.credits, r.input_tokens, r.output_tokens, r.model_key, r.created_at from wf_agent_runs r join wf_agents a on a.id = r.agent_id and a.tenant_id = r.tenant_id where a.user_id = ? and a.id = ? order by r.created_at desc limit '.max(1, min(100, $limit)), [$userId, $agentId]));
    }

    public function usage(string $userId): array
    {
        $per = array_map(fn ($r) => ['id' => trim((string) $r->id), 'name' => (string) $r->name, 'runs' => (int) $r->runs, 'failed' => (int) $r->failed, 'credits' => (string) $r->credits],
            DB::select("select a.id, a.name, count(r.id) as runs, count(r.id) filter (where r.status <> 'ok') as failed, coalesce(sum(r.credits), 0) as credits from wf_agents a left join wf_agent_runs r on r.agent_id = a.id and r.tenant_id = a.tenant_id where a.user_id = ? group by a.id, a.name order by lower(a.name)", [$userId]));

        return ['agents' => count($per), 'runs' => array_sum(array_column($per, 'runs')), 'failed' => array_sum(array_column($per, 'failed')),
            'credits' => number_format(array_sum(array_map('floatval', array_column($per, 'credits'))), 4, '.', ''), 'per_agent' => $per];
    }

    public function limit(string $tenantId): ?float
    {
        $v = DB::selectOne('select wf_agent_limit(?) as r', [$tenantId])->r;

        return $v === null ? null : (float) $v;
    }
}
