<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Social\SocialRepo;

class FakeSocialRepo implements SocialRepo
{
    /** @var list<array{0: string, 1: array<int, mixed>}> */
    public array $calls = [];

    public string $answer = 'ok';

    public ?\Throwable $throw = null;

    /** @var array<string, array<string, mixed>> */
    public array $posts = [];

    private function rec(string $m, array $a): string
    {
        $this->calls[] = [$m, $a];
        if ($this->throw !== null) {
            throw $this->throw;
        }

        return $this->answer;
    }

    public function between(string $from, string $to, bool $withUnscheduled): array
    {
        $this->calls[] = ['between', [$from, $to, $withUnscheduled]];

        return array_values($this->posts);
    }

    public function recent(int $limit): array
    {
        $this->calls[] = ['recent', [$limit]];

        return array_values($this->posts);
    }

    public function find(string $id): ?array
    {
        return $this->posts[$id] ?? null;
    }

    public function save(bool $new, string $tenantId, string $userId, string $id, string $title, string $platform, string $caption, string $kind, ?string $mediaId, ?string $size, ?string $scheduledAt): string
    {
        return $this->rec('save', func_get_args());
    }

    public function submit(string $tenantId, string $userId, string $id): string
    {
        return $this->rec('submit', func_get_args());
    }

    public function decide(string $tenantId, string $approverId, string $id, bool $approve, ?string $note): string
    {
        return $this->rec('decide', func_get_args());
    }

    public function reopen(string $tenantId, string $userId, string $id): string
    {
        return $this->rec('reopen', func_get_args());
    }

    public function markPosted(string $tenantId, string $userId, string $id, ?string $url): string
    {
        return $this->rec('markPosted', func_get_args());
    }

    public function cancel(string $tenantId, string $userId, string $id): string
    {
        return $this->rec('cancel', func_get_args());
    }

    public function delete(string $tenantId, string $userId, string $id): string
    {
        return $this->rec('delete', func_get_args());
    }

    /** @var list<array<string, mixed>> */
    public array $accountList = [];

    public function accounts(): array
    {
        return $this->accountList;
    }

    public function saveAccount(string $tenantId, string $id, string $pageId, string $name, string $cipher, int $version): string
    {
        return $this->rec('saveAccount', func_get_args()) === 'ok' ? $id : $this->answer;
    }

    public function saveChannel(string $tenantId, string $id, string $channelId, string $name, string $cipher, int $version): string
    {
        return $this->rec('saveChannel', func_get_args()) === 'ok' ? $id : $this->answer;
    }

    public function setYoutube(string $tenantId, string $userId, string $id, ?string $tags, string $privacy, ?string $thumbDesignId, ?string $thumbSize): string
    {
        return $this->rec('setYoutube', func_get_args());
    }

    public function deleteAccount(string $tenantId, string $id): string
    {
        return $this->rec('deleteAccount', func_get_args());
    }

    public function setAccount(string $tenantId, string $userId, string $id, ?string $accountId): string
    {
        return $this->rec('setAccount', func_get_args());
    }

    public function retry(string $tenantId, string $userId, string $id): string
    {
        return $this->rec('retry', func_get_args());
    }

    public ?array $statsRow = null;

    public array $postedRows = [];

    public function stats(string $postId): ?array
    {
        return $this->statsRow;
    }

    public function posted(int $days): array
    {
        $this->calls[] = ['posted', [$days]];

        return $this->postedRows;
    }

    public string $planAnswer = 'ok';

    public function startPlan(string $tenantId, string $userId, string $id, string $brief, int $days, array $platforms): string
    {
        $this->calls[] = ['startPlan', func_get_args()];
        if ($this->throw !== null) {
            throw $this->throw;
        }

        return $this->planAnswer;
    }

    public function finishPlan(string $tenantId, string $userId, string $id, bool $ok, int $items): string
    {
        $this->calls[] = ['finishPlan', func_get_args()];

        return 'ok';
    }

    public function setNote(string $tenantId, string $userId, string $postId, ?string $note): string
    {
        return $this->rec('setNote', func_get_args());
    }
}
