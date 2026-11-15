<?php

declare(strict_types=1);

namespace App\Knowledge;

/** What the chat takes from the Knowledge Base for one question, and where it keeps the sources afterwards. Must never be allowed to break a chat. */
interface KnowledgeContext
{
    /** @return array{text: ?string, sources: list<array{n: int, document_id: string, title: string, seq: int, excerpt: string, cited: bool}>} */
    public function retrieve(string $tenantId, string $userId, string $question): array;

    /** @param list<array{n: int, document_id: string, title: string, seq: int, excerpt: string, cited: bool}> $sources */
    public function record(string $tenantId, string $userId, string $messageId, array $sources): void;
}
