<?php

declare(strict_types=1);

namespace Tests\Support;

use App\GoogleAds\GoogleAdsRepo;

class FakeGoogleAdsRepo implements GoogleAdsRepo
{
    /** @var list<array{0: string, 1: array<int, mixed>}> */
    public array $calls = [];

    public string $answer = 'ok';

    public ?\Throwable $throw = null;

    public array $accountList = [];

    public array $summaryRows = [];

    public array $due = [];

    /** @return list<string> */
    public function names(): array
    {
        return array_column($this->calls, 0);
    }

    private function rec(string $m, array $a): string
    {
        $this->calls[] = [$m, $a];
        if ($this->throw !== null) {
            throw $this->throw;
        }

        return $this->answer;
    }

    public function accounts(): array
    {
        return $this->accountList;
    }

    public function saveAccount(string $tenantId, string $id, string $name, string $customerId, ?string $loginId, string $currency, string $cipher, int $version): string
    {
        return $this->rec('save', func_get_args());
    }

    public function deleteAccount(string $tenantId, string $id): string
    {
        return $this->rec('delete', func_get_args());
    }

    public function summary(int $days): array
    {
        return $this->summaryRows;
    }

    public function syncDue(int $limit, int $minutes): array
    {
        $this->calls[] = ['due', [$limit, $minutes]];
        if ($this->throw !== null) {
            throw $this->throw;
        }
        $d = $this->due;
        $this->due = [];

        return $d;
    }

    public function store(string $tenantId, string $accountId, array $campaigns, array $insights): string
    {
        return $this->rec('store', func_get_args());
    }

    public function fail(string $tenantId, string $accountId, string $note, bool $invalid): string
    {
        return $this->rec('fail', func_get_args());
    }
}
