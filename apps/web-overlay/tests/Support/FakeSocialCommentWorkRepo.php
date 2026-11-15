<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Social\SocialCommentWorkRepo;

final class FakeSocialCommentWorkRepo implements SocialCommentWorkRepo
{
    /** @var list<array<string, mixed>> */
    public array $dueQueue = [];

    /** @var list<array<string, mixed>> */
    public array $claimQueue = [];

    /** @var ?array{tenant_id: string, external_id: string, cipher: string, version: int, status: string} */
    public ?array $cred = null;

    /** @var list<array{0: string, 1: array<int, mixed>}> */
    public array $calls = [];

    public string $ingestAnswer = 'new';

    public bool $throwOnDone = false;

    public bool $throwOnIngest = false;

    public function due(int $limit): array
    {
        $this->calls[] = ['due', [$limit]];

        return array_splice($this->dueQueue, 0, $limit);
    }

    public function ingest(string $tenantId, string $postId, string $remoteId, string $author, string $message, string $at): string
    {
        $this->calls[] = ['ingest', [$tenantId, $postId, $remoteId, $author, $message, $at]];
        if ($this->throwOnIngest) {
            throw new \RuntimeException('db');
        }

        return $this->ingestAnswer;
    }

    public function claim(int $limit): array
    {
        $this->calls[] = ['claim', [$limit]];

        return array_splice($this->claimQueue, 0, $limit);
    }

    public function done(string $tenantId, string $id, string $remoteId): string
    {
        $this->calls[] = ['done', [$tenantId, $id, $remoteId]];
        if ($this->throwOnDone) {
            throw new \RuntimeException('db');
        }

        return 'ok';
    }

    public function fail(string $tenantId, string $id, string $error): string
    {
        $this->calls[] = ['fail', [$tenantId, $id, $error]];

        return 'ok';
    }

    public function credentials(string $accountId): ?array
    {
        $this->calls[] = ['credentials', [$accountId]];

        return $this->cred;
    }

    public function markAccount(string $accountId, string $status): void
    {
        $this->calls[] = ['markAccount', [$accountId, $status]];
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_column($this->calls, 0);
    }
}
