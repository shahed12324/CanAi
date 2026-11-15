<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Social\SocialPublishRepo;

class FakeSocialPublishRepo implements SocialPublishRepo
{
    /** @var list<array<string, mixed>> */
    public array $queue = [];

    /** @var ?array{tenant_id: string, external_id: string, cipher: string, version: int, status: string} */
    public ?array $cred = null;

    /** @var list<array{0: string, 1: array<int, mixed>}> */
    public array $calls = [];

    public bool $throwOnDone = false;

    /** @var list<array<string, mixed>> */
    public array $due = [];

    public bool $throwOnStats = false;

    public function claim(int $limit): array
    {
        $this->calls[] = ['claim', [$limit]];

        return array_splice($this->queue, 0, $limit);
    }

    /** @var list<array<string, mixed>> */
    public array $ytQueue = [];

    public function claimYoutube(int $limit, int $globalCap, int $tenantCap): array
    {
        $this->calls[] = ['claimYoutube', [$limit, $globalCap, $tenantCap]];

        return array_splice($this->ytQueue, 0, $limit);
    }

    public function youtubeDone(string $tenantId, string $id, string $remoteId, ?string $url, ?string $note): string
    {
        $this->calls[] = ['youtubeDone', [$tenantId, $id, $remoteId, $url, $note]];
        if ($this->throwOnDone) {
            throw new \RuntimeException('db down');
        }

        return 'ok';
    }

    public function credentials(string $accountId): ?array
    {
        $this->calls[] = ['credentials', [$accountId]];

        return $this->cred;
    }

    public function done(string $tenantId, string $id, string $remoteId, ?string $url): string
    {
        $this->calls[] = ['done', [$tenantId, $id, $remoteId, $url]];
        if ($this->throwOnDone) {
            throw new \RuntimeException('db down');
        }

        return 'ok';
    }

    public function fail(string $tenantId, string $id, string $error): string
    {
        $this->calls[] = ['fail', [$tenantId, $id, $error]];

        return 'ok';
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

    public function statsDue(int $limit): array
    {
        $this->calls[] = ['statsDue', [$limit]];

        return array_splice($this->due, 0, $limit);
    }

    public function saveStats(string $tenantId, string $postId, ?int $reactions, ?int $comments, ?int $shares, ?int $reach): string
    {
        $this->calls[] = ['saveStats', [$tenantId, $postId, $reactions, $comments, $shares, $reach]];
        if ($this->throwOnStats) {
            throw new \RuntimeException('db down');
        }

        return 'ok';
    }
}
