<?php

declare(strict_types=1);

namespace App\Workflow;

interface WorkflowRepo
{
    /** @return list<array{id: string, name: string, description: string, category: string, definition: array<string, mixed>, version: int, enabled: bool, schedule_time: ?string, max_credits: float, paused: bool, model_id: ?string, template_key: ?string}> */
    public function workflows(string $userId): array;

    /** @return ?array{id: string, name: string, description: string, category: string, definition: array<string, mixed>, version: int, enabled: bool, schedule_time: ?string, max_credits: float, paused: bool, model_id: ?string, template_key: ?string} */
    public function workflow(string $userId, string $id): ?array;

    /** @param array<string, mixed> $definition @return string ok|bad_input|limit|not_found */
    public function save(string $tenantId, string $userId, string $id, string $name, string $desc, string $category, array $definition, bool $enabled, ?string $schedule, float $maxCredits, bool $isNew): string;

    /** Name + description only: never touches the steps, version, template, schedule or history. @return string ok|bad_input|not_found */
    public function rename(string $tenantId, string $userId, string $id, string $name, string $desc): string;

    /** Remember which template a workflow came from (set once). @return string ok|bad_input|not_found */
    public function markTemplate(string $tenantId, string $userId, string $id, string $key): string;

    /** @return string ok|bad_input|not_found */
    public function setMeta(string $tenantId, string $userId, string $id, string $category, ?string $schedule, float $maxCredits, ?string $modelId): string;

    /** @param array<string, mixed> $definition @return string ok|bad_input|not_found */
    public function setDefinition(string $tenantId, string $userId, string $id, array $definition): string;

    /** null = leave as is. @return string ok|not_found */
    public function setState(string $tenantId, string $userId, string $id, ?bool $enabled, ?bool $paused): string;

    /** @return string ok|not_found|limit|bad_input */
    public function duplicate(string $tenantId, string $userId, string $sourceId, string $newId, string $name): string;

    /** @param array<string, string> $vars @return string ok|not_found|busy|bad_input */
    public function createTestRun(string $tenantId, string $userId, string $runId, string $workflowId, array $vars): string;

    /** @return list<array{id: string, workflow_id: string, workflow_name: string, status: string, trigger: string, spent: float, max_credits: float, error: ?string, created_at: string, finished_at: ?string}> newest first */
    public function runsFor(string $userId, string $workflowId, int $limit = 20): array;

    /** @return string ok|not_found */
    public function delete(string $tenantId, string $userId, string $id): string;

    /** @param array<string, string> $vars @return string ok|not_found|disabled|busy|bad_input */
    public function createRun(string $tenantId, string $userId, string $runId, string $workflowId, array $vars): string;

    /** @return list<array{id: string, workflow_id: string, workflow_name: string, status: string, trigger: string, spent: float, max_credits: float, error: ?string, created_at: string, finished_at: ?string}> */
    public function runs(string $userId, int $limit = 20): array;

    /** @return list<array{seq: int, node_id: string, node_type: string, status: string, output: string, credits: float}> */
    public function steps(string $userId, string $runId): array;

    /** @return list<array{run_id: string, workflow_name: string, message: string}> runs of this person waiting for a yes/no */
    public function pendingApprovals(string $userId): array;

    /** @return string ok|not_found|not_waiting */
    public function decide(string $tenantId, string $userId, string $runId, bool $approve): string;

    /** @return string ok|not_found|finished */
    public function cancel(string $tenantId, string $userId, string $runId): string;

    /** @return ?array{id: string, user_id: string, status: string, definition: array<string, mixed>, vars: array<string, mixed>, current_node: ?string, spent: float, max_credits: float, steps: int, timezone: string, workflow_name: string, trigger: string, model_id: ?string} */
    public function run(string $runId): ?array;

    /** @param ?array<string, mixed> $vars null = unchanged @return string ok|not_running */
    public function record(string $tenantId, string $runId, string $node, string $type, string $status, string $output, string $credits, ?array $vars, ?string $next): string;

    /** The run reached a delay step: record it, keep the variables, point at $next and park the run for $minutes (1-1440). @param ?array<string, mixed> $vars @return string ok|bad_input|not_running */
    public function delay(string $tenantId, string $runId, string $node, string $next, int $minutes, ?array $vars): string;

    /** @return string ok|not_running */
    public function end(string $tenantId, string $runId, string $status, ?string $error): string;
}
