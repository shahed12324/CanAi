<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Knowledge\KnowledgeRepo;

final class FakeKnowledgeRepo implements KnowledgeRepo
{
    /** @var list<array<string, mixed>> */
    public array $saved = [];

    public string $result = 'ok';

    /** @var list<array{string, int}> */
    public array $searches = [];

    public function documents(): array
    {
        return [];
    }

    public function save(string $tenantId, string $userId, string $id, string $title, string $filename, string $mime, int $size, array $chunks): string
    {
        $this->saved[] = compact('tenantId', 'userId', 'id', 'title', 'filename', 'mime', 'size', 'chunks');

        return $this->result;
    }

    public function update(string $tenantId, string $id, ?string $title, ?bool $active): string
    {
        return 'ok';
    }

    public function delete(string $tenantId, string $id): string
    {
        return 'ok';
    }

    public function search(string $query, int $k): array
    {
        $this->searches[] = [$query, $k];

        return [];
    }

    public function recordSources(string $tenantId, string $userId, string $messageId, array $sources): int
    {
        return count($sources);
    }

    public function sourcesFor(array $messageIds): array
    {
        return [];
    }
}
