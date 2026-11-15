<?php

declare(strict_types=1);

namespace App\Knowledge;

use App\Tenancy\TenantContext;

final class RepoKnowledgeContext implements KnowledgeContext
{
    public function __construct(private readonly KnowledgeRepo $repo, private readonly TenantContext $context) {}

    public function retrieve(string $tenantId, string $userId, string $question): array
    {
        $q = trim($question);
        if ($q === '') {
            return ['text' => null, 'sources' => []];
        }

        return $this->context->run($tenantId, fn () => KnowledgePrompt::block($this->repo->search($q, KnowledgePrompt::MAX_SOURCES + 2)));
    }

    public function record(string $tenantId, string $userId, string $messageId, array $sources): void
    {
        if ($sources !== []) {
            $this->context->run($tenantId, fn () => $this->repo->recordSources($tenantId, $userId, $messageId, $sources));
        }
    }
}
