<?php

namespace Tests\Support;

use App\Reseller\ResellerRepo;

final class FakeResellerRepo implements ResellerRepo
{
    public array $joins = [];

    public string $joinResult = 'ok';

    public ?array $report = null;

    public ?string $of = null;

    public ?array $me = null;

    public function join(string $tenantId, string $userId, string $code): string
    {
        $this->joins[] = [$tenantId, $userId, $code];

        return $this->joinResult;
    }

    public function of(string $tenantId, string $userId): ?string { return $this->of; }

    public function me(string $tenantId, string $userId): ?array { return $this->me; }

    public function report(string $tenantId, string $userId): ?array { return $this->report; }
}
