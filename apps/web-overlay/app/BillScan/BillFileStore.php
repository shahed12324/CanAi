<?php

declare(strict_types=1);

namespace App\BillScan;

/** Where the uploaded bill is kept. Private: it is only ever served through an authenticated, tenant-checked route. */
interface BillFileStore
{
    public function put(string $tenantId, string $scanId, string $mime, string $bytes): void;

    public function get(string $tenantId, string $scanId, string $mime): ?string;

    public function delete(string $tenantId, string $scanId, string $mime): void;
}
