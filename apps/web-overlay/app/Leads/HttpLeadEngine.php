<?php

declare(strict_types=1);

namespace App\Leads;

use App\Services\AiEngine\AiEngineClient;

final class HttpLeadEngine implements LeadEngine
{
    public function __construct(private readonly AiEngineClient $client) {}

    public function crawl(string $tenantId, string $userId, string $url, int $maxPages, bool $renderJs): array
    {
        $res = $this->client->post('/internal/v1/leads/crawl', $tenantId, [
            'url' => $url, 'max_pages' => max(1, min(8, $maxPages)), 'check_email' => true, 'render_js' => $renderJs,
        ], $userId, 110);

        if ($res['status'] === 200 && isset($res['body']['status'])) {
            return $res['body'];
        }
        if ($res['status'] === 422) {
            return ['status' => 'unsafe_url'];                          // the engine refused the request shape: never retry
        }

        throw new \RuntimeException('Lead engine answered HTTP '.$res['status']);
    }
}
