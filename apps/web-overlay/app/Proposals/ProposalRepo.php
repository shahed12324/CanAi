<?php

declare(strict_types=1);

namespace App\Proposals;

/** Persistence for proposals, templates, the brand kit and the portfolio. Postgres in production (RLS applies), an array in tests. */
interface ProposalRepo
{
    /** @return array{id: string, kind: string, language: string, name: string, sections: list<array<string, string>>}|null a shipped template or one of this workspace's */
    public function template(string $id): ?array;

    /** @return array<string, mixed>|null the default brand kit */
    public function brand(): ?array;

    /** @return array{name: string, domain: string, analysis: array<string, mixed>}|null */
    public function leadSummary(string $leadId): ?array;

    /** @param array<string, mixed> $data */
    public function create(string $tenantId, string $userId, ?string $leadId, ?string $templateId, ?string $brandId, string $title, string $language, array $data): string;

    /** @return array{id: string, tenant_id: string, lead_id: ?string, title: string, language: string, status: string, data: array<string, mixed>}|null */
    public function find(string $id): ?array;

    /** @param array<string, mixed> $data */
    public function update(string $id, string $title, string $language, array $data): void;

    public function setStatus(string $id, string $status): void;

    /** @param list<string> $ids @return list<array{id: string, title: string, url: ?string, description: ?string}> in the order given, own workspace only */
    public function portfolioByIds(array $ids): array;
}
