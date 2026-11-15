<?php

declare(strict_types=1);

namespace App\Pc;

use Illuminate\Support\Facades\DB;

final class PgPcPlanRepo implements PcPlanRepo
{
    public function submit(string $tenantId, string $userId, string $deviceId, string $goal, array $plan): array
    {
        $r = DB::selectOne('select pc_plan_submit(?, ?, ?, ?, ?::jsonb) as r', [$tenantId, $userId, $deviceId, $goal, json_encode($plan, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)])->r;
        $a = json_decode((string) $r, true);

        return is_array($a) ? $a : ['status' => 'rejected_input', 'reason' => 'bad_input'];
    }

    public function decide(string $tenantId, string $userId, string $planId, string $decision): string
    {
        return (string) DB::selectOne('select pc_plan_decide(?, ?, ?, ?) as r', [$tenantId, $userId, $planId, $decision])->r;
    }

    public function stepApprove(string $tenantId, string $userId, string $planId, string $stepId): string
    {
        return (string) DB::selectOne('select pc_plan_step_approve(?, ?, ?, ?) as r', [$tenantId, $userId, $planId, $stepId])->r;
    }

    public function mobileStepApprove(string $deviceId, string $userId, string $planId, string $stepId): string
    {
        return (string) DB::selectOne('select mobile_pc_plan_step_approve(?, ?, ?, ?) as r', [$deviceId, $userId, $planId, $stepId])->r;
    }

    public function next(string $tenantId, string $deviceId): ?array
    {
        $v = DB::selectOne('select pc_plan_next(?, ?) as r', [$tenantId, $deviceId])->r;
        if ($v === null) {
            return null;
        }
        $a = json_decode((string) $v, true);

        return is_array($a) ? $a : null;
    }

    public function report(string $tenantId, string $deviceId, string $planId, string $state, string $result): string
    {
        return (string) DB::selectOne('select pc_plan_report(?, ?, ?, ?, ?) as r', [$tenantId, $deviceId, $planId, $state, $result])->r;
    }

    public function recent(string $tenantId, string $userId, int $limit): array
    {
        return DB::table('pc_plans as p')->join('pc_devices as d', 'd.id', '=', 'p.device_id')
            ->where('p.tenant_id', $tenantId)->where('d.user_id', $userId)->orderByDesc('p.created_at')->limit(max(1, min(100, $limit)))
            ->get(['p.id', 'p.device_id', 'd.name as device', 'p.goal', 'p.plan_text', 'p.needs', 'p.status', 'p.reason', 'p.result', 'p.created_at', 'p.expires_at'])
            ->map(function ($r) {
                $steps = json_decode((string) $r->plan_text, true)['steps'] ?? [];
                $need = array_filter(explode(',', trim((string) $r->needs, '{}')));

                return ['id' => trim((string) $r->id), 'device_id' => trim((string) $r->device_id), 'device' => $r->device, 'goal' => $r->goal,
                    'steps' => array_map(static fn ($s) => ['id' => (string) ($s['id'] ?? ''), 'kind' => (string) ($s['kind'] ?? ''), 'target' => (string) ($s['params']['path'] ?? (isset($s['params']['src']) ? $s['params']['src'].' -> '.($s['params']['dst'] ?? '') : ''))], is_array($steps) ? $steps : []),
                    'needs' => array_values($need), 'status' => $r->status, 'reason' => $r->reason, 'result' => $r->result, 'created_at' => $r->created_at, 'expires_at' => $r->expires_at];
            })->all();
    }
}
