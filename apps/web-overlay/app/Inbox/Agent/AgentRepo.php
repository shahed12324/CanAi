<?php

declare(strict_types=1);

namespace App\Inbox\Agent;

interface AgentRepo
{
    /** @return array{instructions: string, business_info: string} */
    public function settings(): array;

    public function saveSettings(string $tenantId, string $instructions, string $businessInfo): void;

    public function setChannelMode(string $channelId, string $mode): void;

    /**
     * Published products best matching the keywords (live copy + current stock per variant), newest first when nothing matches.
     *
     * @param  list<string>  $keywords
     * @return array{products: list<array<string, mixed>>, matched: bool, categories: list<string>}
     */
    public function search(array $keywords, int $limit = 5): array;

    /** Products (live copy + stock) that own these variants — used to keep an order draft's items priced while the customer talks about something else. @param list<string> $variantIds @return list<array<string, mixed>> */
    public function byVariants(array $variantIds): array;

    /** One in-stock sibling for a gentle upsell: same category, not already chosen. @param list<string> $excludeProductIds @return list<array<string, mixed>> */
    public function related(?string $category, array $excludeProductIds, int $limit = 1): array;

    public function saveDraft(string $tenantId, string $conversationId, string $body, int $inSeq): void;

    /** @return array{body: string, in_seq: int}|null */
    public function draft(string $conversationId): ?array;

    public function deleteDraft(string $conversationId): void;
}
