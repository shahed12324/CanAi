<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Ads\AdsLaunchRepo;

class FakeAdsLaunchRepo implements AdsLaunchRepo
{
    /** @var list<array{0: string, 1: array<int, mixed>}> */
    public array $calls = [];

    /** @var list<array<string, mixed>> */
    public array $due = [];

    public string $stepAnswer = 'ok';

    public string $doneAnswer = 'ok';

    public ?\Throwable $throw = null;

    /** @return list<string> */
    public function names(): array
    {
        return array_column($this->calls, 0);
    }

    public function claim(int $limit, int $hourlyCap): array
    {
        $this->calls[] = ['claim', [$limit, $hourlyCap]];
        if ($this->throw !== null) {
            throw $this->throw;
        }
        $d = $this->due;
        $this->due = [];

        return $d;
    }

    public function step(string $tenantId, string $draftId, string $field, string $remoteId): string
    {
        $this->calls[] = ['step', [$tenantId, $draftId, $field, $remoteId]];

        return $this->stepAnswer;
    }

    public function done(string $tenantId, string $draftId): string
    {
        $this->calls[] = ['done', [$tenantId, $draftId]];

        return $this->doneAnswer;
    }

    public function fail(string $tenantId, string $draftId, string $error, bool $unknown): string
    {
        $this->calls[] = ['fail', [$tenantId, $draftId, $error, $unknown]];

        return 'ok';
    }
}
