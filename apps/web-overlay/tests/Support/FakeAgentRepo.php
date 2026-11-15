<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Inbox\Agent\AgentRepo;

final class FakeAgentRepo implements AgentRepo
{
    public array $settings = ['instructions' => '', 'business_info' => 'ঢাকায় ডেলিভারি ৬০ টাকা।'];

    public array $found = ['products' => [], 'matched' => false, 'categories' => []];

    public array $drafts = [];

    public array $keywordsSeen = [];

    public array $modes = [];

    public function settings(): array
    {
        return $this->settings;
    }

    public function saveSettings(string $tenantId, string $instructions, string $businessInfo): void
    {
        $this->settings = ['instructions' => $instructions, 'business_info' => $businessInfo];
    }

    public function setChannelMode(string $channelId, string $mode): void
    {
        $this->modes[$channelId] = $mode;
    }

    public function search(array $keywords, int $limit = 5): array
    {
        $this->keywordsSeen[] = $keywords;

        return $this->found;
    }

    public array $variantProducts = [];

    public array $relatedProducts = [];

    public function byVariants(array $variantIds): array
    {
        return $this->variantProducts;
    }

    public function related(?string $category, array $excludeProductIds, int $limit = 1): array
    {
        return $this->relatedProducts;
    }

    public function saveDraft(string $tenantId, string $conversationId, string $body, int $inSeq): void
    {
        $this->drafts[$conversationId] = ['body' => $body, 'in_seq' => $inSeq];
    }

    public function draft(string $conversationId): ?array
    {
        return $this->drafts[$conversationId] ?? null;
    }

    public function deleteDraft(string $conversationId): void
    {
        unset($this->drafts[$conversationId]);
    }
}
