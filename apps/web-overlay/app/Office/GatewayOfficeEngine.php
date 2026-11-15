<?php

declare(strict_types=1);

namespace App\Office;

use App\Ai\ProviderGateway;
use App\Services\AiEngine\AiEngineClient;

final class GatewayOfficeEngine implements OfficeEngine
{
    public function __construct(private readonly ProviderGateway $gateway, private readonly AiEngineClient $client) {}

    public function complete(string $providerId, string $modelKey, array $messages, string $tenantId, string $userId, int $maxTokens): array
    {
        $r = $this->gateway->complete($providerId, $modelKey, $messages, $tenantId, $userId, $maxTokens, 0.3);

        return ['text' => (string) ($r['text'] ?? ''), 'input_tokens' => $r['input_tokens'] ?? null, 'output_tokens' => $r['output_tokens'] ?? null, 'model' => (string) ($r['model'] ?? $modelKey),
            'finish_reason' => $r['finish_reason'] ?? null];
    }

    public function build(string $kind, array $spec, string $tenantId, string $userId): string
    {
        if (! isset(OfficeSpec::KINDS[$kind])) {
            throw new \InvalidArgumentException('unknown kind');
        }
        $res = $this->client->postRaw('/internal/v1/office/'.$kind, $tenantId, $spec, $userId, 60);
        if ($res['status'] === 200 && $res['body'] !== '') {
            return $res['body'];
        }

        throw new \RuntimeException('Office engine answered HTTP '.$res['status']);
    }
}
