<?php

declare(strict_types=1);

namespace App\Knowledge;

use Illuminate\Support\Facades\DB;

final class PgKnowledgeRepo implements KnowledgeRepo
{
    public function documents(): array
    {
        return array_map(fn ($r) => ['id' => trim((string) $r->id), 'title' => (string) $r->title, 'filename' => (string) $r->filename, 'mime' => (string) $r->mime, 'size_bytes' => (int) $r->size_bytes,
            'char_count' => (int) $r->char_count, 'chunk_count' => (int) $r->chunk_count, 'active' => (bool) $r->active, 'created_at' => (string) $r->created_at],
            DB::select('select id, title, filename, mime, size_bytes, char_count, chunk_count, active, created_at from kb_documents order by created_at desc, id'));
    }

    public function save(string $tenantId, string $userId, string $id, string $title, string $filename, string $mime, int $size, array $chunks): string
    {
        return (string) DB::selectOne('select kb_document_save(?, ?, ?, ?, ?, ?, ?, ?::text[]) as r', [$id, $tenantId, $userId, $title, $filename, $mime, $size, self::pgArray($chunks)])->r;
    }

    public function update(string $tenantId, string $id, ?string $title, ?bool $active): string
    {
        return (string) DB::selectOne('select kb_document_update(?, ?, ?, ?::boolean) as r', [$id, $tenantId, $title, $active === null ? null : ($active ? 'true' : 'false')])->r;
    }

    public function delete(string $tenantId, string $id): string
    {
        return (string) DB::selectOne('select kb_document_delete(?, ?) as r', [$id, $tenantId])->r;
    }

    public function search(string $query, int $k): array
    {
        return array_map(fn ($r) => ['document_id' => trim((string) $r->document_id), 'title' => (string) $r->title, 'seq' => (int) $r->seq, 'content' => (string) $r->content, 'rank' => (float) $r->rank],
            DB::select('select document_id, title, seq, content, rank from kb_search(?, ?)', [$query, $k]));
    }

    public function recordSources(string $tenantId, string $userId, string $messageId, array $sources): int
    {
        $items = array_map(fn ($s) => ['n' => $s['n'], 'document_id' => $s['document_id'], 'title' => $s['title'], 'seq' => $s['seq'], 'excerpt' => $s['excerpt'], 'cited' => $s['cited']], $sources);

        return (int) DB::selectOne('select chat_sources_save(?, ?, ?, ?::jsonb) as r', [$tenantId, $userId, $messageId, json_encode($items, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)])->r;
    }

    public function sourcesFor(array $messageIds): array
    {
        if ($messageIds === []) {
            return [];
        }
        $out = [];
        $rows = DB::select('select message_id, n, title, passage_seq, excerpt, cited from chat_message_sources where message_id = any(?::text[]) order by message_id, n', [self::pgArray($messageIds)]);
        foreach ($rows as $r) {
            $out[trim((string) $r->message_id)][] = ['n' => (int) $r->n, 'title' => (string) $r->title, 'seq' => (int) $r->passage_seq, 'excerpt' => (string) $r->excerpt, 'cited' => (bool) $r->cited];
        }

        return $out;
    }

    /** @param  list<string>  $items */
    public static function pgArray(array $items): string
    {
        return '{'.implode(',', array_map(fn ($s) => '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $s).'"', $items)).'}';
    }
}
