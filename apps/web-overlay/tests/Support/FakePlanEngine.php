<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Pc\PlanEngine;

final class FakePlanEngine implements PlanEngine
{
    public int $calls = 0;

    /** @var list<array<string, mixed>> */
    public array $seen = [];

    public function __construct(public mixed $answer = null) {}

    public function draft(string $providerId, string $modelKey, string $goal, array $roots, array $kinds, string $tenantId, string $userId, array $apps = [], array $hosts = []): array
    {
        $this->calls++;
        $this->seen[] = compact('goal', 'roots', 'kinds', 'apps', 'hosts');
        if ($this->answer instanceof \Throwable) {
            throw $this->answer;
        }

        return $this->answer ?? ['status' => 'ok', 'plan' => ['steps' => [['id' => 's1', 'kind' => 'make_dir', 'params' => ['path' => '/home/me/work/new']]]], 'review' => ['steps' => 1], 'input_tokens' => 800, 'output_tokens' => 200];
    }
}
