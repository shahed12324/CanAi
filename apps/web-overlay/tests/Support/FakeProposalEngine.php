<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Proposals\ProposalEngine;
use App\Proposals\ProposalEngineUnavailable;

final class FakeProposalEngine implements ProposalEngine
{
    public array $calls = [];

    public bool $pdfDown = false;

    public function render(string $tenantId, string $userId, array $payload, string $format): string
    {
        $this->calls[] = compact('tenantId', 'userId', 'payload', 'format');
        if ($format === 'pdf' && $this->pdfDown) {
            throw new ProposalEngineUnavailable('down');
        }

        return $format === 'pdf' ? "%PDF-fake\n" : "PK-fake";
    }
}
