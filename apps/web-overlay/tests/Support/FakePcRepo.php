<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Pc\PcRepo;

class FakePcRepo implements PcRepo
{
    public string $createResult = 'ok';

    /** @var list<array<string, mixed>> */
    public array $created = [];

    /** @var array<string, array{device_id: string, tenant_id: string}> code hash => device */
    public array $codes = [];

    /** @var array<string, array<string, string>> */
    public array $tokens = [];

    public array $requestResult = ['status' => 'pending', 'reason' => null, 'id' => 'ACT00000000000000000000001', 'risk' => 'low'];

    public function pairCreate(string $tenantId, string $userId, string $name, string $platform, string $codeHash): string
    {
        $this->created[] = compact('tenantId', 'userId', 'name', 'platform', 'codeHash');
        if ($this->createResult === 'ok') {
            $this->codes[$codeHash] = ['device_id' => 'DEV'.str_pad((string) count($this->created), 23, '0', STR_PAD_LEFT), 'tenant_id' => $tenantId];
        }

        return $this->createResult;
    }

    public function pairRedeem(string $codeHash, string $tokenHash): ?array
    {
        $d = $this->codes[$codeHash] ?? null;
        if ($d === null) {
            return null;
        }
        unset($this->codes[$codeHash]);
        $this->tokens[$tokenHash] = $d + ['user_id' => 'U'];

        return $d;
    }

    public int $lookups = 0;

    public function agentRotate(string $oldHash, string $newHash): ?array
    {
        if (! isset($this->tokens[$oldHash]) || isset($this->tokens[$newHash]) || $oldHash === $newHash) {
            return null;
        }
        $this->tokens[$newHash] = $this->tokens[$oldHash];
        unset($this->tokens[$oldHash]);

        return $this->tokens[$newHash];
    }

    public function agentLookup(string $tokenHash): ?array
    {
        $this->lookups++;
        return $this->tokens[$tokenHash] ?? null;
    }

    public function deviceRevoke(string $tenantId, string $userId, string $deviceId): string
    {
        return 'ok';
    }

    public function policySave(string $tenantId, string $userId, string $deviceId, array $p): string
    {
        return 'ok';
    }

    public array $auditHeadResult = ['status' => 'ok', 'seq' => 0, 'h' => '0000000000000000000000000000000000000000000000000000000000000000'];

    public array $auditIngestResult = ['status' => 'ok', 'seq' => 1, 'h' => 'a'];

    /** @var list<array{0: string, 1: string, 2: int, 3: array}> */
    public array $auditCalls = [];

    public function auditHead(string $tenantId, string $deviceId): array
    {
        return $this->auditHeadResult;
    }

    public function auditIngest(string $tenantId, string $deviceId, int $start, array $rows): array
    {
        $this->auditCalls[] = [$tenantId, $deviceId, $start, $rows];

        return $this->auditIngestResult;
    }

    public function actionRequest(string $tenantId, string $userId, string $deviceId, string $kind, array $params): array
    {
        return $this->requestResult;
    }

    public function actionDecide(string $tenantId, string $userId, string $actionId, string $decision): string
    {
        return 'ok';
    }

    public function actionNext(string $tenantId, string $deviceId): ?array
    {
        return null;
    }

    public function actionReport(string $tenantId, string $deviceId, string $actionId, bool $ok, string $result): string
    {
        return 'ok';
    }

    /** @var list<array<string, mixed>> */
    public bool $emergency = false;

    public function agentEmergency(string $tenantId, string $deviceId): bool
    {
        return $this->emergency;
    }

    public array $deviceRows = [];

    public ?array $policyRow = null;

    /** @var list<array<string, mixed>> */
    public array $actionRows = [];

    public ?array $metricRow = null;

    public function devices(string $tenantId, string $userId): array
    {
        return $this->deviceRows;
    }

    public function policy(string $tenantId, string $deviceId): ?array
    {
        return $this->policyRow;
    }

    public function actions(string $tenantId, string $userId, int $limit): array
    {
        return array_slice($this->actionRows, 0, $limit);
    }

    /** @var list<array<string, mixed>> */
    public array $mobileRows = [];

    public string $mobileResult = 'ok';

    /** @var list<array<string, string>> */
    public array $mobileCalls = [];

    public function mobilePending(string $deviceId, string $userId): array
    {
        $this->mobileCalls[] = compact('deviceId', 'userId');

        return $this->mobileRows;
    }

    public function mobileDecide(string $deviceId, string $userId, string $actionId, string $decision): string
    {
        $this->mobileCalls[] = compact('deviceId', 'userId', 'actionId', 'decision');

        return $this->mobileResult;
    }

    public function latestMetric(string $tenantId, string $deviceId): ?array
    {
        return $this->metricRow;
    }

    public function metricsAdd(string $tenantId, string $deviceId, array $m): string
    {
        return 'ok';
    }
}
