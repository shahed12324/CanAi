<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Crm\CrmStore;

/** Mirrors the SQL behaviour (lead_move_stage etc.) on top of FakeLeadStore's leads. */
final class FakeCrmStore implements CrmStore
{
    public array $activities = [];

    public array $tasks = [];

    private int $n = 0;

    public function __construct(public FakeLeadStore $leads) {}

    public function lead(string $leadId): ?array
    {
        $l = $this->leads->leads[$leadId] ?? null;

        return $l === null ? null : ['id' => $leadId, 'stage' => $l['stage'], 'tenant_id' => $l['tenant']];
    }

    public function moveStage(string $leadId, string $stage, string $userId): ?string
    {
        if (! isset($this->leads->leads[$leadId])) {
            return null;
        }
        $old = $this->leads->leads[$leadId]['stage'];
        if ($old === $stage) {
            return $old;
        }
        $this->leads->leads[$leadId]['stage'] = $stage;
        if (in_array($stage, ['won', 'lost'], true)) {
            $this->leads->leads[$leadId]['follow_up'] = null;
        }
        $this->addActivity($this->leads->leads[$leadId]['tenant'], $leadId, $userId, 'stage', '', ['from' => $old, 'to' => $stage]);

        return $old;
    }

    public function addActivity(string $tenantId, string $leadId, ?string $userId, string $kind, string $body, array $meta = []): void
    {
        $this->activities[] = compact('tenantId', 'leadId', 'userId', 'kind', 'body', 'meta');
    }

    public function addTask(string $tenantId, string $leadId, string $userId, string $title, ?string $dueAt, ?string $assigneeId): string
    {
        $id = 'T'.str_pad((string) (++$this->n), 25, '0', STR_PAD_LEFT);
        $this->tasks[$id] = ['tenant' => $tenantId, 'lead' => $leadId, 'title' => $title, 'due' => $dueAt, 'assignee' => $assigneeId, 'done' => false];

        return $id;
    }

    public function completeTask(string $taskId): ?array
    {
        $t = $this->tasks[$taskId] ?? null;
        if ($t === null || $t['done']) {
            return null;
        }
        $this->tasks[$taskId]['done'] = true;

        return ['lead_id' => $t['lead'], 'tenant_id' => $t['tenant'], 'title' => $t['title']];
    }

    public function setTags(string $leadId, array $tags): void
    {
        $this->leads->leads[$leadId]['tags'] = $tags;
    }

    public function setFollowUp(string $leadId, ?string $at): void
    {
        $this->leads->leads[$leadId]['follow_up'] = $at;
    }

    public function leadForCompany(string $companyId): ?string
    {
        foreach ($this->leads->leads as $id => $l) {
            if ($l['company'] === $companyId) {
                return $id;
            }
        }

        return null;
    }
}
