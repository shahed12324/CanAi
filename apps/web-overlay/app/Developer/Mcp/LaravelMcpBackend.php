<?php

declare(strict_types=1);

namespace App\Developer\Mcp;

use App\Credits\CreditService;
use App\Workflow\WorkflowDefinition;
use App\Workflow\WorkflowRepo;
use App\Workflow\WorkflowService;

/** MCP tools for one key: bound to that key's person and workspace at construction. */
final class LaravelMcpBackend implements McpBackend
{
    public function __construct(private readonly string $tenantId, private readonly string $userId, private readonly WorkflowRepo $workflows, private readonly WorkflowService $service, private readonly CreditService $credits) {}

    public function workflows(): array
    {
        $out = [];
        foreach ($this->workflows->workflows($this->userId) as $w) {
            $out[] = ['id' => $w['id'], 'name' => $w['name'], 'enabled' => $w['enabled'], 'inputs' => array_column(WorkflowDefinition::inputs($w['definition']), 'key')];
        }

        return $out;
    }

    public function run(string $id, array $inputs, bool $dryRun): array
    {
        $r = $this->service->start($this->tenantId, $this->userId, $id, $inputs, $dryRun);

        return ['status' => $r['status'], 'run_id' => $r['run_id']];
    }

    public function runStatus(string $id): ?array
    {
        $run = $this->workflows->run($id);

        return $run === null || $run['user_id'] !== $this->userId ? null : ['status' => $run['status'], 'spent' => $run['spent'], 'max_credits' => $run['max_credits']];
    }

    public function credits(): array
    {
        $w = $this->credits->wallet($this->tenantId);

        return ['balance' => $w === null ? '0' : (string) $w->balance, 'reserved' => $w === null ? '0' : (string) $w->reserved, 'available' => $w === null ? '0' : (string) $w->available];
    }
}
