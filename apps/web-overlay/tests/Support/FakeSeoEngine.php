<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Seo\SeoEngine;

final class FakeSeoEngine implements SeoEngine
{
    /** @var array<string, mixed> */
    public array $result = ['status' => 'ok', 'url' => 'https://alo.com/', 'final_url' => 'https://alo.com/', 'domain' => 'alo.com', 'pages_checked' => 2, 'score' => 77, 'keyword' => null,
        'site' => ['https' => true, 'robots_txt' => true, 'sitemap' => false, 'sitemap_urls' => 0],
        'issues' => [['code' => 'no_sitemap', 'severity' => 'medium', 'label' => 'sitemap নেই', 'advice' => 'বানান', 'count' => 1, 'pages' => ['https://alo.com']]],
        'pages' => [['url' => 'https://alo.com/', 'status' => 200, 'title' => 'আলো', 'words' => 410, 'issues' => ['no_og']]]];

    public bool $down = false;

    /** @var list<array{string, string, string, int, ?string}> */
    public array $calls = [];

    public function audit(string $tenantId, string $userId, string $url, int $maxPages, ?string $keyword): array
    {
        $this->calls[] = [$tenantId, $userId, $url, $maxPages, $keyword];
        if ($this->down) {
            throw new \RuntimeException('engine down');
        }

        return $this->result;
    }
}
