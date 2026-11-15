<?php

declare(strict_types=1);

namespace App\Seo;

use App\Actions\Tenancy\CreateWorkspace;

/**
 * Runs one SEO audit: validate the address, refuse BEFORE crawling when the daily limit is used up, ask the engine (rule-based, no AI, no credits),
 * keep only the fields we render, store the result.  Nothing is published or spent.
 */
final class SeoService
{
    public function __construct(private readonly SeoEngine $engine, private readonly SeoRepo $repo) {}

    /**
     * @return array{outcome: string, id: ?string, status: ?string} outcome: ok | bad_url | limit | client | too_large | engine_down | error;  status = the engine's own status when it answered
     */
    public function run(string $tenantId, string $userId, string $rawUrl, int $maxPages, ?string $rawKeyword, ?string $clientId): array
    {
        $url = SeoText::url($rawUrl);
        if ($url === null) {
            return ['outcome' => 'bad_url', 'id' => null, 'status' => null];
        }
        $keyword = SeoText::keyword($rawKeyword);
        try {
            if (! $this->repo->allowed($tenantId)) {
                return ['outcome' => 'limit', 'id' => null, 'status' => null];
            }
            $raw = $this->engine->audit($tenantId, $userId, $url, max(1, min(8, $maxPages)), $keyword);
        } catch (\Throwable) {
            return ['outcome' => 'engine_down', 'id' => null, 'status' => null];
        }
        $result = SeoText::clean($raw);
        $id = CreateWorkspace::newId();
        try {
            $outcome = $this->repo->save($tenantId, $userId, $id, $clientId, $url, (string) ($result['domain'] ?? ''), $keyword, $result['status'], $result['status'] === 'ok' ? (int) $result['score'] : null, (int) ($result['pages_checked'] ?? 0), $result);
        } catch (\Throwable) {
            return ['outcome' => 'error', 'id' => null, 'status' => $result['status']];
        }

        return ['outcome' => $outcome, 'id' => $outcome === 'ok' ? $id : null, 'status' => $result['status']];
    }
}
