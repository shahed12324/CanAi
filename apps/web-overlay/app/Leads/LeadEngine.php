<?php

declare(strict_types=1);

namespace App\Leads;

interface LeadEngine
{
    /**
     * Crawl one site. Returns the engine's result array (status ok|blocked_by_robots|unreachable|unsafe_url|not_html|timeout|...).
     *
     * @return array<string, mixed>
     * @throws \RuntimeException when the engine itself cannot be reached or answered with a server error (worth retrying)
     */
    public function crawl(string $tenantId, string $userId, string $url, int $maxPages, bool $renderJs): array;
}
