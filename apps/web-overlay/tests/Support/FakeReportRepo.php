<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Reports\ReportRepo;

class FakeReportRepo implements ReportRepo
{
    /** @var list<array{0: string, 1: array<int, mixed>}> */
    public array $calls = [];

    /** @var array<string, list<array<string, mixed>>> rows by "platform:from" */
    public array $ads = [];

    public array $socialData = ['posts' => [], 'reactions' => 0, 'comments' => 0, 'shares' => 0, 'reach' => 0, 'top' => null];

    public ?array $brandData = null;

    /** @var list<?string> client id passed on every call */
    public array $clients = [];

    public function ads(string $platform, string $from, string $to, ?string $clientId = null): array
    {
        $this->calls[] = ['ads', [$platform, $from, $to]];
        $this->clients[] = $clientId;

        return $this->ads["$platform:$from"] ?? [];
    }

    public function social(string $from, string $to, string $zone, ?string $clientId = null): array
    {
        $this->calls[] = ['social', [$from, $to, $zone]];
        $this->clients[] = $clientId;

        return $this->socialData;
    }

    public function brand(?string $clientId = null): ?array
    {
        $this->clients[] = $clientId;
        return $this->brandData;
    }
}
