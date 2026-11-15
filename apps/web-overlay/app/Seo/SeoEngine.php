<?php

declare(strict_types=1);

namespace App\Seo;

interface SeoEngine
{
    /**
     * Audit one site. Returns the engine's result array (status ok|blocked_by_robots|unreachable|unsafe_url|not_html|timeout).
     *
     * @return array<string, mixed>
     * @throws \RuntimeException when the engine cannot be reached or answers with a server error
     */
    public function audit(string $tenantId, string $userId, string $url, int $maxPages, ?string $keyword): array;
}
