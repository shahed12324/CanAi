<?php

declare(strict_types=1);

namespace App\Knowledge;

interface KnowledgeRepo
{
    /** @return list<array{id: string, title: string, filename: string, mime: string, size_bytes: int, char_count: int, chunk_count: int, active: bool, created_at: string}> */
    public function documents(): array;

    /** @param  list<string>  $chunks  @return string ok|bad_input|duplicate|limit */
    public function save(string $tenantId, string $userId, string $id, string $title, string $filename, string $mime, int $size, array $chunks): string;

    /** @return string ok|not_found|bad_input */
    public function update(string $tenantId, string $id, ?string $title, ?bool $active): string;

    /** @return string ok|not_found */
    public function delete(string $tenantId, string $id): string;

    /** @return list<array{document_id: string, title: string, seq: int, content: string, rank: float}> */
    public function search(string $query, int $k): array;

    /** @param list<array{n: int, document_id: string, title: string, seq: int, excerpt: string, cited: bool}> $sources  @return int rows stored */
    public function recordSources(string $tenantId, string $userId, string $messageId, array $sources): int;

    /** @param list<string> $messageIds  @return array<string, list<array{n: int, title: string, seq: int, excerpt: string, cited: bool}>> by message id */
    public function sourcesFor(array $messageIds): array;
}
