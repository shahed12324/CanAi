<?php

declare(strict_types=1);

namespace App\Workflow;

final class ToolRegistry
{
    /** @var array<string, Tool> */
    private array $tools = [];

    /** @param list<Tool> $tools */
    public function __construct(array $tools)
    {
        foreach ($tools as $t) {
            $this->tools[$t->key()] = $t;
        }
    }

    public function get(string $key): ?Tool
    {
        return $this->tools[$key] ?? null;
    }

    /** @return array<string, Tool> */
    public function all(): array
    {
        return $this->tools;
    }
}
