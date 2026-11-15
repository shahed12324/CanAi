<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Voice\VoiceActions;

final class FakeVoiceActions implements VoiceActions
{
    /** @var list<array{id: string, name: string, enabled: bool, needs_input: bool}> */
    public array $list = [];

    public string $result = 'ok';

    /** @var list<array{string, string, string}> */
    public array $started = [];

    public ?string $listedFor = null;

    public function workflows(string $userId): array
    {
        $this->listedFor = $userId;

        return $this->list;
    }

    public function startWorkflow(string $tenantId, string $userId, string $workflowId): string
    {
        $this->started[] = [$tenantId, $userId, $workflowId];

        return $this->result;
    }
}
