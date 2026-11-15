<?php

declare(strict_types=1);

namespace App\Leads;

/** Persistence for research jobs and the lead core. Postgres in production (RLS applies), an array in tests. */
interface LeadStore
{
    /** @param list<array{url: string, domain: string}> $items */
    public function createJob(string $tenantId, string $userId, string $name, string $inputType, array $items, array $options): string;

    /** @return list<string> item ids */
    public function itemIds(string $jobId): array;

    /** Atomically queued -> running (attempts+1). @return array{id: string, job_id: string, url: string, domain: string, tenant_id: string, user_id: string, options: array<string, mixed>}|null null when someone else has it or the job is cancelled */
    public function claimItem(string $itemId): ?array;

    public function requeueItem(string $itemId): void;

    public function finishItem(string $itemId, string $status, string $code, ?string $companyId): void;

    /** Marks the job done when no item is queued/running. */
    public function refreshJob(string $jobId): void;

    /** @return array{id: string, name: string, score: ?int}|null live company with this domain */
    public function findCompany(string $tenantId, string $domain): ?array;

    /** @param array<string, mixed> $data */
    public function createCompany(string $tenantId, array $data): string;

    /** @param array<string, mixed> $data */
    public function updateCompany(string $companyId, array $data): void;

    /** @return bool true when a live lead was created, false when one already existed */
    public function ensureLead(string $tenantId, string $companyId, int $score, string $source, ?string $jobId, ?string $ownerId): bool;

    public function updateLeadScore(string $tenantId, string $companyId, int $score): void;

    /** @param array<string, mixed> $c  keys: email?, email_status?, phone?, phone_type?, source_url? @return bool true = stored, false = already known */
    public function addContact(string $tenantId, string $companyId, array $c): bool;

    public function contactCount(string $companyId): int;
}
