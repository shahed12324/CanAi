<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Proposals\ProposalRepo;

final class FakeProposalRepo implements ProposalRepo
{
    public array $templates = [];

    public ?array $brand = null;

    public array $leads = [];

    public array $proposals = [];

    public array $portfolio = [];

    private int $n = 0;

    public function template(string $id): ?array
    {
        return $this->templates[$id] ?? null;
    }

    public function brand(): ?array
    {
        return $this->brand;
    }

    public function leadSummary(string $leadId): ?array
    {
        return $this->leads[$leadId] ?? null;
    }

    public function create(string $tenantId, string $userId, ?string $leadId, ?string $templateId, ?string $brandId, string $title, string $language, array $data): string
    {
        $id = 'p'.str_pad((string) (++$this->n), 25, '0', STR_PAD_LEFT);
        $this->proposals[$id] = ['id' => $id, 'tenant_id' => $tenantId, 'lead_id' => $leadId, 'title' => $title, 'language' => $language, 'status' => 'draft', 'data' => $data];

        return $id;
    }

    public function find(string $id): ?array
    {
        return $this->proposals[$id] ?? null;
    }

    public function update(string $id, string $title, string $language, array $data): void
    {
        $this->proposals[$id] = ['title' => $title, 'language' => $language, 'data' => $data] + $this->proposals[$id];
    }

    public function setStatus(string $id, string $status): void
    {
        $this->proposals[$id]['status'] = $status;
    }

    public function portfolioByIds(array $ids): array
    {
        return array_values(array_filter(array_map(fn ($i) => $this->portfolio[$i] ?? null, $ids)));
    }
}
