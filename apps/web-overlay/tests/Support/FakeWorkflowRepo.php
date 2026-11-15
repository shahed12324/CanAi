<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Workflow\WorkflowRepo;

/** In-memory twin of the wf_* functions: only a 'running' run accepts steps; deciding/cancelling follow the same rules. */
final class FakeWorkflowRepo implements WorkflowRepo
{
    /** @var array<string, array<string, mixed>> */
    public array $wfs = [];

    /** @var array<string, array<string, mixed>> */
    public array $runs = [];

    /** @var list<array<string, mixed>> */
    public array $steps = [];

    public ?int $maxWorkflows = null;

    public function workflows(string $userId): array
    {
        return array_values(array_filter($this->wfs, fn ($w) => $w['user'] === $userId));
    }

    public function workflow(string $userId, string $id): ?array
    {
        $w = $this->wfs[$id] ?? null;

        return $w !== null && $w['user'] === $userId ? $w : null;
    }

    public function save(string $tenantId, string $userId, string $id, string $name, string $desc, string $category, array $definition, bool $enabled, ?string $schedule, float $maxCredits, bool $isNew): string
    {
        if ($isNew && $this->maxWorkflows !== null && count($this->wfs) >= $this->maxWorkflows) {
            return 'limit';
        }
        if ($isNew) {
            $this->wfs[$id] = ['id' => $id, 'user' => $userId, 'name' => $name, 'description' => $desc, 'category' => $category, 'definition' => $definition, 'version' => 1, 'enabled' => $enabled, 'schedule_time' => $schedule, 'max_credits' => $maxCredits,
                'paused' => false, 'model_id' => null, 'template_key' => null];

            return 'ok';
        }
        if (! isset($this->wfs[$id]) || $this->wfs[$id]['user'] !== $userId) {
            return 'not_found';
        }
        $w = &$this->wfs[$id];
        $w['version'] += $w['definition'] != $definition ? 1 : 0;
        $w = ['name' => $name, 'description' => $desc, 'category' => $category, 'definition' => $definition, 'enabled' => $enabled, 'schedule_time' => $schedule, 'max_credits' => $maxCredits] + $w;

        return 'ok';
    }

    private function own(string $userId, string $id): bool
    {
        return isset($this->wfs[$id]) && $this->wfs[$id]['user'] === $userId;
    }

    public function rename(string $tenantId, string $userId, string $id, string $name, string $desc): string
    {
        $name = trim((string) preg_replace('/\s+/u', ' ', $name));
        if ($name === '' || mb_strlen($name) > 120 || mb_strlen($desc) > 500) {
            return 'bad_input';
        }
        if (! $this->own($userId, $id)) {
            return 'not_found';
        }
        $this->wfs[$id]['name'] = $name;
        $this->wfs[$id]['description'] = trim($desc);

        return 'ok';
    }

    public function markTemplate(string $tenantId, string $userId, string $id, string $key): string
    {
        if (preg_match('/\A[a-z0-9_]{1,40}\z/', $key) !== 1) {
            return 'bad_input';
        }
        if (! $this->own($userId, $id)) {
            return 'not_found';
        }
        $this->wfs[$id]['template_key'] ??= $key;

        return 'ok';
    }

    public function setMeta(string $tenantId, string $userId, string $id, string $category, ?string $schedule, float $maxCredits, ?string $modelId): string
    {
        if ($maxCredits < 0 || $maxCredits > 1000) {
            return 'bad_input';
        }
        if (! $this->own($userId, $id)) {
            return 'not_found';
        }
        $this->wfs[$id] = ['category' => $category, 'schedule_time' => $schedule, 'max_credits' => $maxCredits, 'model_id' => $modelId] + $this->wfs[$id];

        return 'ok';
    }

    public function setDefinition(string $tenantId, string $userId, string $id, array $definition): string
    {
        if (! $this->own($userId, $id)) {
            return 'not_found';
        }
        $this->wfs[$id]['version'] += $this->wfs[$id]['definition'] != $definition ? 1 : 0;
        $this->wfs[$id]['definition'] = $definition;

        return 'ok';
    }

    public function setState(string $tenantId, string $userId, string $id, ?bool $enabled, ?bool $paused): string
    {
        if (! $this->own($userId, $id)) {
            return 'not_found';
        }
        $this->wfs[$id]['enabled'] = $enabled ?? $this->wfs[$id]['enabled'];
        $this->wfs[$id]['paused'] = $paused ?? ($this->wfs[$id]['paused'] ?? false);

        return 'ok';
    }

    public function duplicate(string $tenantId, string $userId, string $sourceId, string $newId, string $name): string
    {
        if (trim($name) === '' || mb_strlen($name) > 120) {
            return 'bad_input';
        }
        if (! $this->own($userId, $sourceId)) {
            return 'not_found';
        }
        if ($this->maxWorkflows !== null && count($this->wfs) >= $this->maxWorkflows) {
            return 'limit';
        }
        $this->wfs[$newId] = ['id' => $newId, 'name' => $name, 'version' => 1, 'enabled' => false, 'paused' => false, 'schedule_time' => null] + $this->wfs[$sourceId];

        return 'ok';
    }

    public function createTestRun(string $tenantId, string $userId, string $runId, string $workflowId, array $vars): string
    {
        $w = $this->workflow($userId, $workflowId);
        if ($w === null) {
            return 'not_found';
        }
        foreach ($this->runs as $r) {
            if ($r['workflow_id'] === $workflowId && in_array($r['status'], ['queued', 'running', 'waiting_approval'], true)) {
                return 'busy';
            }
        }
        $this->runs[$runId] = ['id' => $runId, 'workflow_id' => $workflowId, 'user_id' => $userId, 'status' => 'queued', 'definition' => $w['definition'], 'vars' => $vars, 'current_node' => $w['definition']['start'],
            'spent' => 0.0, 'max_credits' => $w['max_credits'], 'timezone' => 'Asia/Dhaka', 'workflow_name' => $w['name'], 'error' => null, 'trigger' => 'test', 'model_id' => $w['model_id'] ?? null];

        return 'ok';
    }

    public function runsFor(string $userId, string $workflowId, int $limit = 20): array
    {
        return array_values(array_filter($this->runs, fn ($r) => $r['user_id'] === $userId && $r['workflow_id'] === $workflowId));
    }

    public function delete(string $tenantId, string $userId, string $id): string
    {
        if (! isset($this->wfs[$id]) || $this->wfs[$id]['user'] !== $userId) {
            return 'not_found';
        }
        unset($this->wfs[$id]);

        return 'ok';
    }

    public function createRun(string $tenantId, string $userId, string $runId, string $workflowId, array $vars): string
    {
        $w = $this->workflow($userId, $workflowId);
        if ($w === null) {
            return 'not_found';
        }
        if (! $w['enabled']) {
            return 'disabled';
        }
        foreach ($this->runs as $r) {
            if ($r['workflow_id'] === $workflowId && in_array($r['status'], ['queued', 'running', 'waiting_approval'], true)) {
                return 'busy';
            }
        }
        $this->runs[$runId] = ['id' => $runId, 'workflow_id' => $workflowId, 'user_id' => $userId, 'status' => 'queued', 'definition' => $w['definition'], 'vars' => $vars, 'current_node' => $w['definition']['start'],
            'spent' => 0.0, 'max_credits' => $w['max_credits'], 'timezone' => 'Asia/Dhaka', 'workflow_name' => $w['name'], 'error' => null, 'trigger' => 'manual', 'model_id' => $w['model_id'] ?? null];

        return 'ok';
    }

    /** Test helper: what wf_run_claim does. */
    public function claim(string $runId): void
    {
        $this->runs[$runId]['status'] = 'running';
    }

    public function runs(string $userId, int $limit = 20): array
    {
        $out = [];
        foreach (array_reverse(array_values($this->runs)) as $r) {
            if ($r['user_id'] === $userId) {
                $out[] = $r + ['workflow_name' => '', 'trigger' => 'manual', 'spent' => 0.0, 'error' => null, 'created_at' => '', 'finished_at' => null];
            }
        }

        return array_slice($out, 0, $limit);
    }

    public function steps(string $userId, string $runId): array
    {
        return array_values(array_filter($this->steps, fn ($s) => $s['run'] === $runId));
    }

    public function pendingApprovals(string $userId): array
    {
        return [];
    }

    public function decide(string $tenantId, string $userId, string $runId, bool $approve): string
    {
        $r = $this->runs[$runId] ?? null;
        if ($r === null || $r['user_id'] !== $userId) {
            return 'not_found';
        }
        if ($r['status'] !== 'waiting_approval') {
            return 'not_waiting';
        }
        if ($approve) {
            $this->runs[$runId]['status'] = 'queued';
            $this->runs[$runId]['vars']['__approved:'.$r['current_node']] = true;
        } else {
            $this->runs[$runId]['status'] = 'cancelled';
        }

        return 'ok';
    }

    public function cancel(string $tenantId, string $userId, string $runId): string
    {
        $r = $this->runs[$runId] ?? null;
        if ($r === null || $r['user_id'] !== $userId) {
            return 'not_found';
        }
        if (! in_array($r['status'], ['queued', 'running', 'waiting_approval'], true)) {
            return 'finished';
        }
        $this->runs[$runId]['status'] = 'cancelled';

        return 'ok';
    }

    public function run(string $runId): ?array
    {
        $r = $this->runs[$runId] ?? null;

        return $r === null ? null : $r + ['steps' => count($this->steps($r['user_id'], $runId))];
    }

    public ?\Closure $beforeRecord = null;

    public function record(string $tenantId, string $runId, string $node, string $type, string $status, string $output, string $credits, ?array $vars, ?string $next): string
    {
        if ($this->beforeRecord !== null) {
            ($this->beforeRecord)($this, $runId, $node);
        }
        if (($this->runs[$runId]['status'] ?? '') !== 'running') {
            return 'not_running';
        }
        $this->steps[] = ['run' => $runId, 'seq' => count($this->steps($this->runs[$runId]['user_id'], $runId)) + 1, 'node_id' => $node, 'node_type' => $type, 'status' => $status, 'output' => $output, 'credits' => (float) $credits];
        if ($vars !== null) {
            $this->runs[$runId]['vars'] = $vars;
        }
        $this->runs[$runId]['current_node'] = $next;
        $this->runs[$runId]['spent'] += (float) $credits;

        return 'ok';
    }

    /** @var array<string, int> minutes each run was parked for */
    public array $parked = [];

    public function delay(string $tenantId, string $runId, string $node, string $next, int $minutes, ?array $vars): string
    {
        if ($minutes < 1 || $minutes > 1440 || $next === '') {
            return 'bad_input';
        }
        if (($this->runs[$runId]['status'] ?? '') !== 'running') {
            return 'not_running';
        }
        $this->steps[] = ['run' => $runId, 'seq' => count($this->steps($this->runs[$runId]['user_id'], $runId)) + 1, 'node_id' => $node, 'node_type' => 'delay', 'status' => 'ok', 'output' => $minutes.' মিনিট অপেক্ষা', 'credits' => 0.0];
        if ($vars !== null) {
            $this->runs[$runId]['vars'] = $vars;
        }
        $this->runs[$runId]['current_node'] = $next;
        $this->runs[$runId]['status'] = 'queued';
        $this->parked[$runId] = $minutes;

        return 'ok';
    }

    public function end(string $tenantId, string $runId, string $status, ?string $error): string
    {
        if (($this->runs[$runId]['status'] ?? '') !== 'running') {
            return 'not_running';
        }
        $this->runs[$runId]['status'] = $status;
        $this->runs[$runId]['error'] = $error;

        return 'ok';
    }
}
