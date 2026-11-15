<?php

declare(strict_types=1);

namespace App\Memory;

use Illuminate\Support\Facades\DB;

/** Reads are tenant-scoped by RLS and ALSO filtered to the person here (RLS alone would show a colleague's personal notes). Writes go through functions. */
final class PgMemoryRepo implements MemoryRepo
{
    public function list(string $userId): array
    {
        return array_map(fn ($r) => ['id' => trim((string) $r->id), 'scope' => (string) $r->scope, 'category' => (string) $r->category, 'content' => (string) $r->content, 'pinned' => (bool) $r->pinned,
            'active' => (bool) $r->active, 'source' => (string) $r->source, 'updated_at' => (string) $r->updated_at],
            DB::select("select id, scope, category, content, pinned, active, source, updated_at from memories where scope = 'business' or user_id = ? order by (scope = 'user') desc, pinned desc, updated_at desc", [$userId]));
    }

    public function forChat(string $userId, int $limit): array
    {
        if (! $this->useInChat($userId)) {
            return [];
        }

        return array_map(fn ($r) => ['content' => (string) $r->content, 'scope' => (string) $r->scope],
            DB::select("select content, scope from memories where active and (scope = 'business' or user_id = ?) order by pinned desc, (scope = 'user') desc, updated_at desc limit ?", [$userId, max(1, min(200, $limit))]));
    }

    public function useInChat(string $userId): bool
    {
        $r = DB::selectOne('select use_in_chat from memory_settings where user_id = ?', [$userId]);

        return $r === null ? true : (bool) $r->use_in_chat;
    }

    public function add(string $tenantId, string $userId, string $id, string $scope, string $category, string $content, bool $pinned, string $source, ?string $conversationId): string
    {
        return (string) DB::selectOne('select memory_add(?, ?, ?, ?, ?, ?, ?::boolean, ?, ?) as r', [$id, $tenantId, $userId, $scope, $category, $content, $pinned ? 'true' : 'false', $source, $conversationId])->r;
    }

    public function update(string $tenantId, string $userId, string $id, bool $isManager, ?string $content, ?string $category, ?bool $pinned, ?bool $active): string
    {
        return (string) DB::selectOne('select memory_update(?, ?, ?, ?::boolean, ?, ?, ?::boolean, ?::boolean) as r',
            [$id, $tenantId, $userId, $isManager ? 'true' : 'false', $content, $category, $pinned === null ? null : ($pinned ? 'true' : 'false'), $active === null ? null : ($active ? 'true' : 'false')])->r;
    }

    public function delete(string $tenantId, string $userId, string $id, bool $isManager): string
    {
        return (string) DB::selectOne('select memory_delete(?, ?, ?, ?::boolean) as r', [$id, $tenantId, $userId, $isManager ? 'true' : 'false'])->r;
    }

    public function clear(string $tenantId, string $userId): int
    {
        return (int) DB::selectOne('select memory_clear(?, ?) as r', [$tenantId, $userId])->r;
    }

    public function saveSetting(string $tenantId, string $userId, bool $use): string
    {
        return (string) DB::selectOne('select memory_setting_save(?, ?, ?::boolean) as r', [$tenantId, $userId, $use ? 'true' : 'false'])->r;
    }

    public function pendingSuggestions(string $userId): array
    {
        return array_map(fn ($r) => ['id' => trim((string) $r->id), 'category' => (string) $r->category, 'content' => (string) $r->content],
            DB::select("select id, category, content from memory_suggestions where user_id = ? and status = 'pending' order by created_at, id", [$userId]));
    }

    public function saveSuggestions(string $tenantId, string $userId, string $conversationId, array $items, array $ids): int
    {
        return (int) DB::selectOne('select memory_suggest_save(?, ?, ?, ?::jsonb, ?::text[]) as r',
            [$tenantId, $userId, $conversationId, json_encode($items, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), '{'.implode(',', $ids).'}'])->r;
    }

    public function acceptSuggestion(string $tenantId, string $userId, string $id, string $memoryId): string
    {
        return (string) DB::selectOne('select memory_suggestion_accept(?, ?, ?, ?) as r', [$id, $tenantId, $userId, $memoryId])->r;
    }

    public function dismissSuggestion(string $tenantId, string $userId, string $id): string
    {
        return (string) DB::selectOne('select memory_suggestion_dismiss(?, ?, ?) as r', [$id, $tenantId, $userId])->r;
    }

    public function recentChats(string $userId, int $limit): array
    {
        return array_map(fn ($r) => ['id' => trim((string) $r->id), 'title' => (string) $r->title],
            DB::select('select id, title from conversations where user_id = ? and deleted_at is null order by last_message_at desc nulls last limit ?', [$userId, max(1, min(50, $limit))]));
    }
}
