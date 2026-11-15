<?php

declare(strict_types=1);

namespace Tests\Support;

use App\BillScan\BillScanRepo;

final class FakeBillRepo implements BillScanRepo
{
    /** @var array<string, array<string, mixed>> */
    public array $scans = [];

    public int $n = 0;

    public bool $failFail = false;

    public function start(string $tenantId, string $userId, string $name, string $mime, int $size, string $sha): array
    {
        foreach ($this->scans as $s) {
            if ($s['sha'] === $sha && in_array($s['status'], ['scanning', 'ready', 'used'], true)) {
                return ['result' => 'duplicate', 'scan_id' => $s['id']];
            }
        }
        $id = str_pad((string) ++$this->n, 26, 's', STR_PAD_LEFT);
        $this->scans[$id] = ['id' => $id, 'sha' => $sha, 'status' => 'scanning', 'name' => $name, 'mime' => $mime, 'error' => null, 'credits' => null, 'extracted' => null];

        return ['result' => 'ok', 'scan_id' => $id];
    }

    public function ready(string $scanId, array $extracted, array $flags, ?string $model, int $in, int $out, string $credits): string
    {
        if (($extracted['lines'] ?? []) === []) {
            return 'bad_input';
        }
        $this->scans[$scanId] = ['status' => 'ready', 'extracted' => $extracted, 'flags' => $flags, 'credits' => $credits, 'in' => $in, 'out' => $out] + $this->scans[$scanId];

        return 'ok';
    }

    public function fail(string $scanId, string $error, ?string $model, int $in, int $out, string $credits): string
    {
        if ($this->failFail) {
            throw new \RuntimeException('db down');
        }
        $this->scans[$scanId] = ['status' => 'failed', 'error' => $error, 'credits' => $credits, 'in' => $in, 'out' => $out] + $this->scans[$scanId];

        return 'ok';
    }

    public function discard(string $scanId, string $userId): string
    {
        return 'ok';
    }

    public function post(string $scanId, string $userId, string $kind, ?string $partyId, string $date, ?string $due, ?string $reference, array $lines, int $vatMinor, ?string $payAccountId): array
    {
        return ['result' => 'ok', 'doc_id' => null, 'number' => null, 'total_minor' => null];
    }

    public function scan(string $scanId): ?array
    {
        return $this->scans[$scanId] ?? null;
    }

    public function recent(int $limit): array
    {
        return array_values($this->scans);
    }
}
