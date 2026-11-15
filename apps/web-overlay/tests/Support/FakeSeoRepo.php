<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Seo\SeoRepo;

final class FakeSeoRepo implements SeoRepo
{
    public bool $allowed = true;

    public ?string $saveReturns = null;     // force a DB outcome (limit|client|too_large)

    public bool $throwOnSave = false;

    /** @var list<array<string, mixed>> */
    public array $saved = [];

    public int $allowedChecks = 0;

    public function allowed(string $tenantId): bool
    {
        $this->allowedChecks++;

        return $this->allowed;
    }

    public function save(string $tenantId, string $userId, string $id, ?string $clientId, string $url, string $domain, ?string $keyword, string $status, ?int $score, int $pages, array $result): string
    {
        if ($this->throwOnSave) {
            throw new \RuntimeException('db');
        }
        if ($this->saveReturns !== null) {
            return $this->saveReturns;
        }
        $this->saved[] = compact('tenantId', 'userId', 'id', 'clientId', 'url', 'domain', 'keyword', 'status', 'score', 'pages', 'result');

        return 'ok';
    }

    public function delete(string $tenantId, string $userId, string $id): string
    {
        return 'ok';
    }

    /** @var list<array<string, mixed>> audits for recent()/find() (each with id, domain, status, score, created_at, previous_score, result) */
    public array $audits = [];

    public function recent(int $limit = 50): array
    {
        return array_slice($this->audits, 0, $limit);
    }

    public function find(string $id): ?array
    {
        foreach ($this->audits as $a) {
            if ($a['id'] === $id) {
                return $a + ['previous_score' => null, 'result' => []];
            }
        }

        return null;
    }
}
