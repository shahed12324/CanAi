<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Ads\AdsDraftRepo;

class FakeAdsDraftRepo implements AdsDraftRepo
{
    /** @var list<array{0: string, 1: array<int, mixed>}> */
    public array $calls = [];

    public string $answer = 'ok';

    public ?\Throwable $throw = null;

    public array $list = [];

    public ?array $capsRow = null;

    private function rec(string $m, array $a): string
    {
        $this->calls[] = [$m, $a];
        if ($this->throw !== null) {
            throw $this->throw;
        }

        return $this->answer;
    }

    public function drafts(): array
    {
        return array_values($this->list);
    }

    public function find(string $id): ?array
    {
        return $this->list[$id] ?? null;
    }

    public function caps(): ?array
    {
        return $this->capsRow;
    }

    public function saveCaps(string $tenantId, string $userId, string $daily, string $total): string
    {
        return $this->rec('saveCaps', func_get_args());
    }

    public function save(bool $new, string $tenantId, string $userId, string $id, array $v): string
    {
        return $this->rec('save', func_get_args());
    }

    public function setPage(string $tenantId, string $userId, string $id, ?string $pageAccountId): string
    {
        return $this->rec('setPage', func_get_args());
    }

    public function retryLaunch(string $tenantId, string $userId, string $id, bool $confirm): string
    {
        return $this->rec('retryLaunch', func_get_args());
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

    public function delete(string $tenantId, string $userId, string $id): string
    {
        return $this->rec('delete', func_get_args());
    }
}
