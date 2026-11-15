<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Agents\AgentRepo;

/** In-memory stand-in for PgAgentRepo (the real rules are tested against PostgreSQL in tests/db/test_wf_agents.py). */
final class FakeAgentStudioRepo implements AgentRepo
{
    /** @var array<string, array<string, mixed>> */
    public array $rows = [];

    /** @var list<array<string, mixed>> */
    public array $runLog = [];

    public bool $failRun = false;

    public ?float $limit = 10.0;

    public function agents(string $userId): array
    {
        return array_values(array_filter($this->rows, fn ($r) => $r['_user'] === $userId));
    }

    public function agent(string $userId, string $id): ?array
    {
        $r = $this->rows[$id] ?? null;

        return $r !== null && $r['_user'] === $userId ? $r : null;
    }

    public function save(string $tenantId, string $userId, string $id, bool $isNew, array $d): string
    {
        if ($isNew && $this->limit !== null && count($this->agents($userId)) >= $this->limit) {
            return 'limit';
        }
        foreach ($this->rows as $o) {
            if ($o['id'] !== $id && $o['_user'] === $userId && mb_strtolower($o['name']) === mb_strtolower($d['name'])) {
                return 'duplicate';
            }
        }
        $this->rows[$id] = $d + ['id' => $id, '_user' => $userId, 'version' => ($this->rows[$id]['version'] ?? 0) + 1, 'created_at' => '', 'updated_at' => ''];

        return 'ok';
    }

    public function delete(string $tenantId, string $userId, string $id): string
    {
        if ($this->agent($userId, $id) === null) {
            return 'not_found';
        }
        unset($this->rows[$id]);

        return 'ok';
    }

    public function restore(string $tenantId, string $userId, string $id, int $version): string
    {
        return $this->agent($userId, $id) === null ? 'not_found' : 'ok';
    }

    public function versions(string $userId, string $id): array
    {
        return [];
    }

    public function recordRun(string $tenantId, string $userId, string $runId, string $agentId, string $kind, string $status, string $input, string $output, string $credits, int $in, int $out, string $model): string
    {
        if ($this->failRun) {
            throw new \RuntimeException('db down');
        }
        $this->runLog[] = compact('runId', 'agentId', 'kind', 'status', 'input', 'output', 'credits', 'in', 'out', 'model');

        return 'ok';
    }

    public function runs(string $userId, string $agentId, int $limit = 20): array
    {
        return [];
    }

    public function usage(string $userId): array
    {
        return ['agents' => count($this->agents($userId)), 'runs' => count($this->runLog), 'failed' => 0, 'credits' => '0.0000', 'per_agent' => []];
    }

    public function limit(string $tenantId): ?float
    {
        return $this->limit;
    }
}
