<?php

declare(strict_types=1);

namespace App\Agents;

interface AgentRepo
{
    /** @return list<array<string, mixed>> the person's own agents */
    public function agents(string $userId): array;

    /** @return array<string, mixed>|null */
    public function agent(string $userId, string $id): ?array;

    /**
     * @param  array<string, mixed>  $d  an AgentSpec::normalise() result's data
     * @return 'ok'|'bad_input'|'limit'|'duplicate'|'not_found'
     */
    public function save(string $tenantId, string $userId, string $id, bool $isNew, array $d): string;

    /** @return 'ok'|'not_found' */
    public function delete(string $tenantId, string $userId, string $id): string;

    /** @return 'ok'|'not_found' */
    public function restore(string $tenantId, string $userId, string $id, int $version): string;

    /** @return list<array{version: int, changed_at: string, snapshot: array<string, mixed>}> newest first */
    public function versions(string $userId, string $id): array;

    /** @return 'ok'|'not_found'|'bad_input' */
    public function recordRun(string $tenantId, string $userId, string $runId, string $agentId, string $kind, string $status, string $input, string $output, string $credits, int $in, int $out, string $model): string;

    /** @return list<array<string, mixed>> */
    public function runs(string $userId, string $agentId, int $limit = 20): array;

    /** @return array{agents: int, runs: int, failed: int, credits: string, per_agent: list<array<string, mixed>>} */
    public function usage(string $userId): array;

    /** Plan limit for this workspace (null = unlimited). */
    public function limit(string $tenantId): ?float;
}
