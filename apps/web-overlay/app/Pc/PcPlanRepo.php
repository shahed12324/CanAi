<?php

declare(strict_types=1);

namespace App\Pc;

/** Thin wrapper over the pc_plan_* database functions (the database is the authority; see phase26d.sql). */
interface PcPlanRepo
{
    /** @param array<string, mixed> $plan {steps: [...]}  @return array{status: string, reason: ?string, id?: string, needs?: list<string>} */
    public function submit(string $tenantId, string $userId, string $deviceId, string $goal, array $plan): array;

    /** @return 'ok'|'not_found'|'not_pending'|'expired'|'emergency_stop'|'bad_input' */
    public function decide(string $tenantId, string $userId, string $planId, string $decision): string;

    /** Website path: refuses high / critical steps ('mobile_required').  @return 'ok'|'not_found'|'not_open'|'not_needed'|'expired'|'emergency_stop'|'mobile_required' */
    public function stepApprove(string $tenantId, string $userId, string $planId, string $stepId): string;

    /** Phone path (needs a live PIN step-up).  @return 'ok'|'not_found'|'not_open'|'not_needed'|'expired'|'emergency_stop'|'step_up_required' */
    public function mobileStepApprove(string $deviceId, string $userId, string $planId, string $stepId): string;

    /** Agent pull: the signed plan (see pc_plan_next) or null.  @return ?array<string, mixed> */
    public function next(string $tenantId, string $deviceId): ?array;

    /** @return 'ok'|'not_running'|'bad_input' */
    public function report(string $tenantId, string $deviceId, string $planId, string $state, string $result): string;

    /** Newest first.  @return list<array<string, mixed>> */
    public function recent(string $tenantId, string $userId, int $limit): array;
}
