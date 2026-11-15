<?php

declare(strict_types=1);

namespace App\Proposals;

use App\Services\AiEngine\AiEngineClient;

final class HttpProposalEngine implements ProposalEngine
{
    public function __construct(private readonly AiEngineClient $client) {}

    public function render(string $tenantId, string $userId, array $payload, string $format): string
    {
        $path = $format === 'docx' ? '/internal/v1/render/proposal.docx' : '/internal/v1/render/proposal.pdf';
        $res = $this->client->postRaw($path, $tenantId, $payload, $userId, 90);
        if ($res['status'] === 200 && $res['body'] !== '') {
            return $res['body'];
        }
        if (in_array($res['status'], [503, 504], true)) {
            throw new ProposalEngineUnavailable('engine cannot render '.$format.' right now');
        }

        throw new \RuntimeException('Proposal engine answered HTTP '.$res['status']);
    }
}
