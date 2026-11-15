<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Memory\MemoryRepo;

final class FakeMemoryRepo implements MemoryRepo
{
    /** @var list<array<string, mixed>> */
    public array $saved = [];

    public int $storeResult = -1;           // -1 = store them all

    /** @param list<array{content: string, scope: string}> $chatItems */
    public function __construct(public array $chatItems = [], public bool $use = true) {}

    public function list(string $userId): array
    {
        return [];
    }

    public function forChat(string $userId, int $limit): array
    {
        return $this->use ? array_slice($this->chatItems, 0, $limit) : [];
    }

    public function useInChat(string $userId): bool
    {
        return $this->use;
    }

    public function add(string $tenantId, string $userId, string $id, string $scope, string $category, string $content, bool $pinned, string $source, ?string $conversationId): string
    {
        return 'ok';
    }

    public function update(string $tenantId, string $userId, string $id, bool $isManager, ?string $content, ?string $category, ?bool $pinned, ?bool $active): string
    {
        return 'ok';
    }

    public function delete(string $tenantId, string $userId, string $id, bool $isManager): string
    {
        return 'ok';
    }

    public function clear(string $tenantId, string $userId): int
    {
        return 0;
    }

    public function saveSetting(string $tenantId, string $userId, bool $use): string
    {
        return 'ok';
    }

    public function pendingSuggestions(string $userId): array
    {
        return [];
    }

    public function saveSuggestions(string $tenantId, string $userId, string $conversationId, array $items, array $ids): int
    {
        $this->saved[] = ['conversation' => $conversationId, 'items' => $items, 'ids' => $ids, 'user' => $userId];

        return $this->storeResult >= 0 ? $this->storeResult : count($items);
    }

    public function acceptSuggestion(string $tenantId, string $userId, string $id, string $memoryId): string
    {
        return 'ok';
    }

    public function dismissSuggestion(string $tenantId, string $userId, string $id): string
    {
        return 'ok';
    }

    public function recentChats(string $userId, int $limit): array
    {
        return [];
    }
}
