<?php

declare(strict_types=1);

namespace Tests\Support;

use App\BillScan\BillScanEngine;

final class FakeBillEngine implements BillScanEngine
{
    /** @var array<string, mixed>|\Throwable */
    public mixed $answer;

    public int $calls = 0;

    /** @var list<array<string, mixed>> */
    public array $seen = [];

    public function __construct(mixed $answer = null)
    {
        $this->answer = $answer ?? ['status' => 'ok', 'pages' => 1, 'data' => ['kind' => 'bill', 'lines' => [['description' => 'x', 'amount_minor' => 5000, 'account_code' => '5100']]], 'flags' => [], 'model' => 'gpt-test', 'input_tokens' => 1500, 'output_tokens' => 300];
    }

    public function scan(string $providerId, string $modelKey, string $mime, string $bytes, array $accounts, string $tenantId, string $userId): array
    {
        $this->calls++;
        $this->seen[] = ['model' => $modelKey, 'mime' => $mime, 'accounts' => $accounts];
        if ($this->answer instanceof \Throwable) {
            throw $this->answer;
        }

        return $this->answer;
    }
}
