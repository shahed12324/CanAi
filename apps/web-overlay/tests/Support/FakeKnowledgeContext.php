<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Knowledge\KnowledgeContext;

final class FakeKnowledgeContext implements KnowledgeContext
{
    /** @var list<string> */
    public array $questions = [];

    /** @var list<array{string, array<int, array<string, mixed>>}> */
    public array $recorded = [];

    public ?\Throwable $recordFails = null;

    public function __construct(private readonly mixed $result = null)
    {
    }

    public function retrieve(string $tenantId, string $userId, string $question): array
    {
        $this->questions[] = $question;
        $r = $this->result ?? ['text' => 'DOCS-BLOCK', 'sources' => [
            ['n' => 1, 'document_id' => 'D1', 'title' => 'দামের তালিকা', 'seq' => 2, 'excerpt' => 'কাজু ১২০০', 'cited' => false],
            ['n' => 2, 'document_id' => 'D2', 'title' => 'নীতিমালা', 'seq' => 1, 'excerpt' => 'ফেরত ৭ দিন', 'cited' => false],
        ]];
        if ($r instanceof \Throwable) {
            throw $r;
        }

        return $r;
    }

    public function record(string $tenantId, string $userId, string $messageId, array $sources): void
    {
        if ($this->recordFails !== null) {
            throw $this->recordFails;
        }
        $this->recorded[] = [$messageId, $sources];
    }
}
