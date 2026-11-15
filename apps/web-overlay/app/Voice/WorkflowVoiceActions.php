<?php

declare(strict_types=1);

namespace App\Voice;

use App\Workflow\WorkflowDefinition;
use App\Workflow\WorkflowRepo;
use App\Workflow\WorkflowService;

final class WorkflowVoiceActions implements VoiceActions
{
    public function __construct(private readonly WorkflowRepo $repo, private readonly WorkflowService $service) {}

    public function workflows(string $userId): array
    {
        return array_map(fn (array $w) => ['id' => $w['id'], 'name' => $w['name'], 'enabled' => $w['enabled'], 'needs_input' => WorkflowDefinition::inputs($w['definition']) !== []], $this->repo->workflows($userId));
    }

    public function startWorkflow(string $tenantId, string $userId, string $workflowId): string
    {
        try {
            $r = $this->service->start($tenantId, $userId, $workflowId, []);
        } catch (\Throwable) {
            return 'failed';
        }

        return in_array($r['status'], ['ok', 'busy', 'disabled', 'not_found', 'missing_input'], true) ? $r['status'] : 'failed';
    }
}
