<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Ads\AdsRepo;

class FakeAdsRepo implements AdsRepo
{
    /** @var list<array{0: string, 1: array<int, mixed>}> */
    public array $calls = [];

    public string $answer = 'ok';

    public ?\Throwable $throw = null;

    public array $accountList = [];

    public array $summaryRows = [];

    public array $due = [];

    private function rec(string $m, array $a): string
    {
        $this->calls[] = [$m, $a];
        if ($this->throw !== null) {
            throw $this->throw;
        }

        return $this->answer;
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_column($this->calls, 0);
    }

    public function accounts(): array
    {
        return $this->accountList;
    }

    public function saveAccount(string $tenantId, string $id, string $name, string $externalId, string $currency, string $cipher, int $version): string
    {
        return $this->rec('saveAccount', func_get_args()) === 'ok' ? $id : $this->answer;
    }

    public function setPixel(string $tenantId, string $id, string $pixel): string
    {
        return $this->rec('setPixel', func_get_args());
    }

    public function deleteAccount(string $tenantId, string $id): string
    {
        return $this->rec('deleteAccount', func_get_args());
    }

    public function summary(int $days): array
    {
        $this->calls[] = ['summary', [$days]];

        return $this->summaryRows;
    }

    public function syncDue(int $limit, int $minutes): array
    {
        $this->calls[] = ['syncDue', [$limit, $minutes]];
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
