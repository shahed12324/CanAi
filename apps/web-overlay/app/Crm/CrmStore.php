<?php

declare(strict_types=1);

namespace App\Crm;

/** Persistence for the CRM side of leads. Postgres in production (RLS applies), an array in tests. */
interface CrmStore
{
    /** @return array{id: string, stage: string, tenant_id: string}|null null when missing or not visible */
    public function lead(string $leadId): ?array;

    /** @return string|null previous stage; null when the lead does not exist. Same stage = no change, nothing logged. */
    public function moveStage(string $leadId, string $stage, string $userId): ?string;

    public function addActivity(string $tenantId, string $leadId, ?string $userId, string $kind, string $body, array $meta = []): void;

    public function addTask(string $tenantId, string $leadId, string $userId, string $title, ?string $dueAt, ?string $assigneeId): string;

    /** @return array{lead_id: string, tenant_id: string, title: string}|null null when the task is missing or already done */
    public function completeTask(string $taskId): ?array;

    /** @param list<string> $tags */
    public function setTags(string $leadId, array $tags): void;

    public function setFollowUp(string $leadId, ?string $at): void;

    /** @return string|null lead id for a company (live lead) */
    public function leadForCompany(string $companyId): ?string;
}
