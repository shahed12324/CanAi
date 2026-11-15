<?php

declare(strict_types=1);

namespace App\Pc;

use Illuminate\Support\Facades\DB;

final class PgPcRepo implements PcRepo
{
    public function pairCreate(string $tenantId, string $userId, string $name, string $platform, string $codeHash): string
    {
        return (string) DB::selectOne('select pc_pair_create(?, ?, ?, ?, ?) as r', [$tenantId, $userId, $name, $platform, $codeHash])->r;
    }

    public function pairRedeem(string $codeHash, string $tokenHash): ?array
    {
        return $this->json(DB::selectOne('select pc_pair_redeem(?, ?) as r', [$codeHash, $tokenHash])->r);
    }

    public function agentLookup(string $tokenHash): ?array
    {
        return $this->json(DB::selectOne('select pc_agent_lookup(?) as r', [$tokenHash])->r);
    }

    public function agentRotate(string $oldHash, string $newHash): ?array
    {
        return $this->json(DB::selectOne('select pc_agent_rotate(?, ?) as r', [$oldHash, $newHash])->r);
    }

    public function deviceRevoke(string $tenantId, string $userId, string $deviceId): string
    {
        return (string) DB::selectOne('select pc_device_revoke(?, ?, ?) as r', [$tenantId, $userId, $deviceId])->r;
    }

    public function policySave(string $tenantId, string $userId, string $deviceId, array $p): string
    {
        $arr = fn (string $k): string => '{'.implode(',', array_map(fn ($v) => '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], (string) $v).'"', $p[$k] ?? [])).'}';

        return (string) DB::selectOne('select pc_policy_save(?, ?, ?, ?::text[], ?::text[], ?::text[], ?::text[], ?::jsonb) as r', [
            $tenantId, $userId, $deviceId, $arr('roots'), $arr('apps'), $arr('commands'), $arr('hosts'), json_encode((object) ($p['modes'] ?? []), JSON_THROW_ON_ERROR),
        ])->r;
    }

    public function actionRequest(string $tenantId, string $userId, string $deviceId, string $kind, array $params): array
    {
        $r = DB::selectOne('select pc_action_request(?, ?, ?, ?, ?::jsonb) as r', [$tenantId, $userId, $deviceId, $kind, json_encode((object) $params, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)])->r;

        return $this->json($r) ?? ['status' => 'rejected_input', 'reason' => 'bad_input'];
    }

    public function actionDecide(string $tenantId, string $userId, string $actionId, string $decision): string
    {
        return (string) DB::selectOne('select pc_action_decide(?, ?, ?, ?) as r', [$tenantId, $userId, $actionId, $decision])->r;
    }

    public function mobilePending(string $deviceId, string $userId): array
    {
        $out = [];
        foreach (DB::select('select id, kind, risk, device, params::text as params, created_at::text as created_at, expires_at::text as expires_at from mobile_pc_pending(?, ?)', [$deviceId, $userId]) as $r) {
            $out[] = ['id' => trim((string) $r->id), 'kind' => (string) $r->kind, 'risk' => (string) $r->risk, 'device' => (string) $r->device,
                'params' => $this->json($r->params) ?? [], 'created_at' => (string) $r->created_at, 'expires_at' => (string) $r->expires_at];
        }

        return $out;
    }

    public function mobileDecide(string $deviceId, string $userId, string $actionId, string $decision): string
    {
        return (string) DB::selectOne('select mobile_pc_decide(?, ?, ?, ?) as r', [$deviceId, $userId, $actionId, $decision])->r;
    }

    public function agentEmergency(string $tenantId, string $deviceId): bool
    {
        return (bool) DB::selectOne('select pc_agent_emergency(?, ?) as r', [$tenantId, $deviceId])->r;
    }

    public function actionNext(string $tenantId, string $deviceId): ?array
    {
        return $this->json(DB::selectOne('select pc_action_next(?, ?) as r', [$tenantId, $deviceId])->r);
    }

    public function actionReport(string $tenantId, string $deviceId, string $actionId, bool $ok, string $result): string
    {
        return (string) DB::selectOne('select pc_action_report(?, ?, ?, ?::boolean, ?) as r', [$tenantId, $deviceId, $actionId, $ok ? 'true' : 'false', $result])->r;
    }

    public function devices(string $tenantId, string $userId): array
    {
        return DB::table('pc_devices')->where('tenant_id', $tenantId)->where('user_id', $userId)->whereNull('revoked_at')->orderBy('paired_at')
            ->get(['id', 'name', 'platform', 'last_seen_at', 'user_id'])
            ->map(fn ($d) => ['id' => trim((string) $d->id), 'name' => $d->name, 'platform' => $d->platform, 'last_seen_at' => $d->last_seen_at, 'user_id' => trim((string) $d->user_id)])->all();
    }

    public function policy(string $tenantId, string $deviceId): ?array
    {
        $p = DB::table('pc_policies')->where('tenant_id', $tenantId)->where('device_id', $deviceId)
            ->selectRaw('to_jsonb(roots) as roots, to_jsonb(apps) as apps, to_jsonb(commands) as commands, to_jsonb(hosts) as hosts, modes')->first();
        if ($p === null) {
            return null;
        }
        $list = fn (mixed $v): array => array_values(array_map('strval', (array) json_decode((string) $v, true)));

        return ['roots' => $list($p->roots), 'apps' => $list($p->apps), 'commands' => $list($p->commands), 'hosts' => $list($p->hosts), 'modes' => (array) json_decode((string) $p->modes, true)];
    }

    public function actions(string $tenantId, string $userId, int $limit): array
    {
        return DB::table('pc_actions as a')->join('pc_devices as d', 'd.id', '=', 'a.device_id')->where('a.tenant_id', $tenantId)->where('d.user_id', $userId)
            ->orderByDesc('a.created_at')->limit(max(1, min(200, $limit)))
            ->get(['a.id', 'a.device_id', 'd.name as device', 'a.kind', 'a.risk', 'a.params', 'a.status', 'a.reason', 'a.result', 'a.created_at'])
            ->map(fn ($a) => ['id' => trim((string) $a->id), 'device_id' => trim((string) $a->device_id), 'device' => $a->device, 'kind' => $a->kind, 'risk' => $a->risk,
                'params' => (array) json_decode((string) $a->params, true), 'status' => $a->status, 'reason' => $a->reason, 'result' => $a->result, 'created_at' => (string) $a->created_at])->all();
    }

    public function latestMetric(string $tenantId, string $deviceId): ?array
    {
        $m = DB::table('pc_metrics')->where('tenant_id', $tenantId)->where('device_id', $deviceId)->orderByDesc('created_at')->first(['cpu', 'mem', 'disk', 'gpu', 'battery', 'temp_c', 'charging', 'created_at']);

        return $m === null ? null : [
            'cpu' => (float) $m->cpu, 'mem' => (float) $m->mem, 'disk' => (float) $m->disk, 'gpu' => $m->gpu === null ? null : (float) $m->gpu,
            'battery' => $m->battery === null ? null : (float) $m->battery, 'temp_c' => $m->temp_c === null ? null : (float) $m->temp_c,
            'charging' => $m->charging === null ? null : (bool) $m->charging, 'created_at' => (string) $m->created_at,
        ];
    }

    public function metricsAdd(string $tenantId, string $deviceId, array $m): string
    {
        $n = fn (string $k) => isset($m[$k]) ? (string) $m[$k] : null;
        $c = array_key_exists('charging', $m) && $m['charging'] !== null ? ($m['charging'] ? 'true' : 'false') : null;

        return (string) DB::selectOne('select pc_metrics_add(?, ?, ?::numeric, ?::numeric, ?::numeric, ?::numeric, ?::numeric, ?::numeric, ?::boolean) as r',
            [$tenantId, $deviceId, $n('cpu'), $n('mem'), $n('disk'), $n('gpu'), $n('battery'), $n('temp_c'), $c])->r;
    }

    public function auditHead(string $tenantId, string $deviceId): array
    {
        return $this->json(DB::selectOne('select pc_audit_head(?, ?) as r', [$tenantId, $deviceId])->r) ?? ['status' => 'not_found'];
    }

    public function auditIngest(string $tenantId, string $deviceId, int $start, array $rows): array
    {
        return $this->json(DB::selectOne('select pc_audit_ingest(?, ?, ?, ?::jsonb) as r', [$tenantId, $deviceId, $start, json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)])->r) ?? ['status' => 'bad_input'];
    }

    /** @return ?array<string, mixed> */
    private function json(mixed $v): ?array
    {
        if ($v === null) {
            return null;
        }
        $a = json_decode((string) $v, true);

        return is_array($a) ? $a : null;
    }
}
