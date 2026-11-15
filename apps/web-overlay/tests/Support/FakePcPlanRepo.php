<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Pc\PcPlanRepo;

final class FakePcPlanRepo implements PcPlanRepo
{
    /** @var list<array<string, mixed>> */
    public array $submitted = [];

    public array $submitResult = ['status' => 'pending', 'id' => 'PLAN0000000000000000000001'];

    public function submit(string $tenantId, string $userId, string $deviceId, string $goal, array $plan): array
    {
        $this->submitted[] = compact('tenantId', 'userId', 'deviceId', 'goal', 'plan');

        return $this->submitResult;
    }

    public function decide(string $tenantId, string $userId, string $planId, string $decision): string { return 'ok'; }

    public function stepApprove(string $tenantId, string $userId, string $planId, string $stepId): string { return 'ok'; }

    public function mobileStepApprove(string $deviceId, string $userId, string $planId, string $stepId): string { return 'ok'; }

    public function next(string $tenantId, string $deviceId): ?array { return null; }

    public function report(string $tenantId, string $deviceId, string $planId, string $state, string $result): string { return 'ok'; }

    public function recent(string $tenantId, string $userId, int $limit): array { return []; }
}
