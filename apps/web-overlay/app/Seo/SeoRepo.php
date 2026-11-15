<?php

declare(strict_types=1);

namespace App\Seo;

interface SeoRepo
{
    /** True while the workspace is under its daily audit limit (checked BEFORE the engine crawls anything). */
    public function allowed(string $tenantId): bool;

    /** @param array<string, mixed> $result the sanitised result @return string ok|limit|client|too_large */
    public function save(string $tenantId, string $userId, string $id, ?string $clientId, string $url, string $domain, ?string $keyword, string $status, ?int $score, int $pages, array $result): string;

    /** @return string ok|missing */
    public function delete(string $tenantId, string $userId, string $id): string;

    /** @return list<array{id: string, url: string, domain: string, keyword: ?string, status: string, score: ?int, pages_checked: int, client_id: ?string, client_name: ?string, created_at: string}> newest first */
    public function recent(int $limit = 50): array;

    /** @return ?array{id: string, url: string, domain: string, keyword: ?string, status: string, score: ?int, pages_checked: int, client_id: ?string, client_name: ?string, created_at: string, result: array<string, mixed>, previous_score: ?int} */
    public function find(string $id): ?array;
}
