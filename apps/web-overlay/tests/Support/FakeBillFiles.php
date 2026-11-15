<?php

declare(strict_types=1);

namespace Tests\Support;

use App\BillScan\BillFileStore;

final class FakeBillFiles implements BillFileStore
{
    /** @var array<string, string> */
    public array $files = [];

    public bool $failPut = false;

    public function put(string $tenantId, string $scanId, string $mime, string $bytes): void
    {
        if ($this->failPut) {
            throw new \RuntimeException('disk full');
        }
        $this->files["$tenantId/$scanId"] = $bytes;
    }

    public function get(string $tenantId, string $scanId, string $mime): ?string
    {
        return $this->files["$tenantId/$scanId"] ?? null;
    }

    public function delete(string $tenantId, string $scanId, string $mime): void
    {
        unset($this->files["$tenantId/$scanId"]);
    }
}
