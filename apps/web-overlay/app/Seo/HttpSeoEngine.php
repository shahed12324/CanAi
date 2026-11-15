<?php

declare(strict_types=1);

namespace App\Seo;

use App\Services\AiEngine\AiEngineClient;

final class HttpSeoEngine implements SeoEngine
{
    public function __construct(private readonly AiEngineClient $client) {}

    public function audit(string $tenantId, string $userId, string $url, int $maxPages, ?string $keyword): array
    {
        $payload = ['url' => $url, 'max_pages' => max(1, min(8, $maxPages))];
        if ($keyword !== null) {
            $payload['keyword'] = $keyword;
        }
        $res = $this->client->post('/internal/v1/seo/audit', $tenantId, $payload, $userId, 110);

        if ($res['status'] === 200 && isset($res['body']['status'])) {
            return $res['body'];
        }
        if ($res['status'] === 422) {
            return ['status' => 'unsafe_url'];                          // the engine refused the request shape: never retry
        }

        throw new \RuntimeException('SEO engine answered HTTP '.$res['status']);
    }
}
