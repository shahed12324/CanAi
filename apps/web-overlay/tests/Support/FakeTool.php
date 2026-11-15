<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Workflow\Risk;
use App\Workflow\Tool;
use App\Workflow\ToolContext;
use App\Workflow\ToolException;

final class FakeTool implements Tool
{
    /** @var list<array<string, string>> */
    public array $calls = [];

    public function __construct(private readonly string $key = 'fake.read', private readonly string $risk = Risk::READ, public string $result = 'টুলের ফল', public ?\Throwable $throws = null, private readonly array $argSpec = ['text' => 'Text', 'more?' => 'See']) {}

    public function key(): string
    {
        return $this->key;
    }

    public function label(): string
    {
        return $this->key;
    }

    public function risk(): string
    {
        return $this->risk;
    }

    public function args(): array
    {
        return $this->argSpec;
    }

    public function run(ToolContext $c, array $args): string
    {
        $this->calls[] = $args;
        if ($this->throws !== null) {
            throw $this->throws;
        }

        return $this->result;
    }
}
