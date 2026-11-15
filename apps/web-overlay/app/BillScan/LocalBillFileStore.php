<?php

declare(strict_types=1);

namespace App\BillScan;

use Illuminate\Support\Facades\Storage;

/** The default private disk (storage/app/private). Names are built from validated ids only, never from the uploaded name. */
final class LocalBillFileStore implements BillFileStore
{
    private static function path(string $tenantId, string $scanId, string $mime): string
    {
        if (preg_match('/\A[0-9a-z]{26}\z/', $tenantId) !== 1 || preg_match('/\A[0-9a-z]{26}\z/', $scanId) !== 1) {
            throw new \InvalidArgumentException('bad id');
        }

        return "bill-scans/{$tenantId}/{$scanId}.".BillFile::ext($mime);
    }

    public function put(string $tenantId, string $scanId, string $mime, string $bytes): void
    {
        if (! Storage::disk('local')->put(self::path($tenantId, $scanId, $mime), $bytes)) {
            throw new \RuntimeException('could not store bill file');
        }
    }

    public function get(string $tenantId, string $scanId, string $mime): ?string
    {
        $p = self::path($tenantId, $scanId, $mime);

        return Storage::disk('local')->exists($p) ? Storage::disk('local')->get($p) : null;
    }

    public function delete(string $tenantId, string $scanId, string $mime): void
    {
        Storage::disk('local')->delete(self::path($tenantId, $scanId, $mime));
    }
}
