<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Ads\AdsAdviceRepo;

class FakeAdsAdviceRepo implements AdsAdviceRepo
{
    /** @var list<array{0: string, 1: array<int, mixed>}> */
    public array $calls = [];

    public array $due = [];

    public string $doneAnswer = 'ok';

    public ?\Throwable $throw = null;

    /** @return list<string> */
    public function names(): array
    {
        return array_column($this->calls, 0);
    }

    public function stats(): array
    {
        return [];
    }

    public function requestPause(string $tenantId, string $userId, string $accountId, string $campaign, string $reason): string
    {
        $this->calls[] = ['requestPause', func_get_args()];

        return 'ok';
    }

    public function requests(): array
    {
        return [];
    }

    public function claim(int $limit): array
    {
        $this->calls[] = ['claim', [$limit]];
        if ($this->throw !== null) {
            throw $this->throw;
        }
        $d = $this->due;
        $this->due = [];

        return $d;
    }

    public function done(string $tenantId, string $reqId, bool $ok, string $error): string
    {
        $this->calls[] = ['done', [$tenantId, $reqId, $ok, $error]];

        return $this->doneAnswer;
    }
}
