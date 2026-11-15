<?php

declare(strict_types=1);

namespace App\Proposals;

interface ProposalEngine
{
    /**
     * @param  array<string, mixed>  $payload  DocumentBuilder::build()
     * @param  'pdf'|'docx'  $format
     * @return string the file bytes
     *
     * @throws ProposalEngineUnavailable when the engine cannot make this format right now
     */
    public function render(string $tenantId, string $userId, array $payload, string $format): string;
}
