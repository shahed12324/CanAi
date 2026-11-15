<?php

declare(strict_types=1);

namespace App\Workflow\Tools;

use App\Knowledge\KnowledgeContext;
use App\Workflow\Risk;
use App\Workflow\Tool;
use App\Workflow\ToolContext;
use App\Workflow\ToolException;

final class KnowledgeSearchTool implements Tool
{
    public function __construct(private readonly KnowledgeContext $kb) {}

    public function key(): string
    {
        return 'knowledge.search';
    }

    public function label(): string
    {
        return 'Search the knowledge base';
    }

    public function risk(): string
    {
        return Risk::READ;
    }

    public function args(): array
    {
        return ['query' => 'What to search'];
    }

    public function run(ToolContext $c, array $args): string
    {
        $q = trim($args['query'] ?? '');
        if ($q === '') {
            throw new ToolException('The search subject is blank.');
        }
        $r = $this->kb->retrieve($c->tenantId, $c->userId, $q);

        return $r['text'] ?? '(No match found in the knowledge base)';
    }
}
