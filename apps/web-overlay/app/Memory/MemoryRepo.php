<?php

declare(strict_types=1);

namespace App\Memory;

interface MemoryRepo
{
    /** @return list<array{id: string, scope: string, category: string, content: string, pinned: bool, active: bool, source: string, updated_at: string}> this person's own memories, then the business-wide ones */
    public function list(string $userId): array;

    /** @return list<array{content: string, scope: string}> what goes into a chat for this person (active ones; pinned first, newest first); empty when they switched memory off */
    public function forChat(string $userId, int $limit): array;

    public function useInChat(string $userId): bool;

    /** @return string ok|bad_input|sensitive|duplicate|limit */
    public function add(string $tenantId, string $userId, string $id, string $scope, string $category, string $content, bool $pinned, string $source, ?string $conversationId): string;

    /** @return string ok|not_found|forbidden|bad_input|sensitive|duplicate */
    public function update(string $tenantId, string $userId, string $id, bool $isManager, ?string $content, ?string $category, ?bool $pinned, ?bool $active): string;

    /** @return string ok|not_found|forbidden */
    public function delete(string $tenantId, string $userId, string $id, bool $isManager): string;

    public function clear(string $tenantId, string $userId): int;

    public function saveSetting(string $tenantId, string $userId, bool $use): string;

    /** @return list<array{id: string, category: string, content: string}> */
    public function pendingSuggestions(string $userId): array;

    /** @param list<array{content: string, category: string}> $items @param list<string> $ids @return int how many were stored */
    public function saveSuggestions(string $tenantId, string $userId, string $conversationId, array $items, array $ids): int;

    public function acceptSuggestion(string $tenantId, string $userId, string $id, string $memoryId): string;

    public function dismissSuggestion(string $tenantId, string $userId, string $id): string;

    /** @return list<array{id: string, title: string}> this person's recent chats */
    public function recentChats(string $userId, int $limit): array;
}
