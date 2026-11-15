<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Social\SocialCommentRepo;

final class FakeSocialCommentRepo implements SocialCommentRepo
{
    /** @var array<string, array<string, mixed>> */
    public array $rows = [];

    /** @var list<array{0: string, 1: array<int, mixed>}> */
    public array $calls = [];

    public string $draftAnswer = 'ok';

    public ?\Throwable $throw = null;

    public function list(string $filter, int $limit): array
    {
        $this->calls[] = ['list', [$filter, $limit]];

        return array_values($this->rows);
    }

    public function find(string $id): ?array
    {
        $this->calls[] = ['find', [$id]];
        if ($this->throw) {
            throw $this->throw;
        }

        return $this->rows[$id] ?? null;
    }

    public function counts(): array
    {
        return [];
    }

    public function draft(string $tenantId, string $userId, string $id, string $text): string
    {
        $this->calls[] = ['draft', [$tenantId, $userId, $id, $text]];

        return $this->draftAnswer;
    }

    public function approve(string $tenantId, string $userId, string $id, string $text): string
    {
        $this->calls[] = ['approve', [$tenantId, $userId, $id, $text]];

        return 'ok';
    }

    public function withdraw(string $tenantId, string $userId, string $id): string
    {
        return 'ok';
    }

    public function ignore(string $tenantId, string $userId, string $id): string
    {
        return 'ok';
    }

    public function retry(string $tenantId, string $userId, string $id): string
    {
        return 'ok';
    }
}
