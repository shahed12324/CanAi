<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Office\OfficeEngine;

final class FakeOfficeEngine implements OfficeEngine
{
    public int $calls = 0;

    public int $builds = 0;

    /** @var list<array{messages: array, max: int}> */
    public array $seen = [];

    /** @var list<array{kind: string, spec: array}> */
    public array $built = [];

    public bool $buildFails = false;

    public function __construct(public mixed $answer = null) {}

    public function complete(string $providerId, string $modelKey, array $messages, string $tenantId, string $userId, int $maxTokens): array
    {
        $this->calls++;
        $this->seen[] = ['messages' => $messages, 'max' => $maxTokens];
        if ($this->answer instanceof \Throwable) {
            throw $this->answer;
        }

        return $this->answer ?? ['text' => '{"title":"রিপোর্ট","blocks":[{"t":"p","text":"হ্যালো"}]}', 'input_tokens' => 900, 'output_tokens' => 400, 'model' => 'gpt-test'];
    }

    public function build(string $kind, array $spec, string $tenantId, string $userId): string
    {
        $this->builds++;
        $this->built[] = ['kind' => $kind, 'spec' => $spec];
        if ($this->buildFails) {
            throw new \RuntimeException('engine down');
        }

        return 'FILE:'.$kind;
    }
}
