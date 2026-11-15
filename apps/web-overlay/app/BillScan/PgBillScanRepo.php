<?php

declare(strict_types=1);

namespace App\BillScan;

use App\Actions\Tenancy\CreateWorkspace;
use Illuminate\Support\Facades\DB;

final class PgBillScanRepo implements BillScanRepo
{
    public function start(string $tenantId, string $userId, string $name, string $mime, int $size, string $sha): array
    {
        $r = DB::selectOne('select result, scan_id from bill_scan_start(?, ?, ?, ?, ?, ?::integer, ?)', [CreateWorkspace::newId(), $tenantId, $userId, $name, $mime, $size, $sha]);

        return ['result' => (string) $r->result, 'scan_id' => $r->scan_id === null ? null : trim((string) $r->scan_id)];
    }

    public function ready(string $scanId, array $extracted, array $flags, ?string $model, int $in, int $out, string $credits): string
    {
        return (string) DB::selectOne('select bill_scan_ready(?, ?::jsonb, ?::text[], ?, ?::integer, ?::integer, ?::numeric) as r',
            [$scanId, json_encode($extracted, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), self::pgArray($flags), $model, $in, $out, $credits])->r;
    }

    public function fail(string $scanId, string $error, ?string $model, int $in, int $out, string $credits): string
    {
        return (string) DB::selectOne('select bill_scan_fail(?, ?, ?, ?::integer, ?::integer, ?::numeric) as r', [$scanId, $error, $model, $in, $out, $credits])->r;
    }

    public function discard(string $scanId, string $userId): string
    {
        return (string) DB::selectOne('select bill_scan_discard(?, ?) as r', [$scanId, $userId])->r;
    }

    public function post(string $scanId, string $userId, string $kind, ?string $partyId, string $date, ?string $due, ?string $reference, array $lines, int $vatMinor, ?string $payAccountId): array
    {
        $r = DB::selectOne('select result, doc_id, number, total_minor from bill_scan_post(?, ?, ?, ?, ?, ?, ?::date, ?::date, ?, ?::jsonb, ?::bigint, ?)',
            [$scanId, CreateWorkspace::newId(), CreateWorkspace::newId(), $userId, $kind, $partyId, $date, $due, $reference, json_encode($lines, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $vatMinor, $payAccountId]);

        return ['result' => (string) $r->result, 'doc_id' => $r->doc_id === null ? null : trim((string) $r->doc_id), 'number' => $r->number, 'total_minor' => $r->total_minor === null ? null : (int) $r->total_minor];
    }

    public function scan(string $scanId): ?array
    {
        $r = DB::selectOne('select id, tenant_id, file_name, mime, size_bytes, status, error, model, input_tokens, output_tokens, credits, extracted, flags, doc_id, created_at from bill_scans where id = ?', [$scanId]);
        if ($r === null) {
            return null;
        }
        $a = (array) $r;
        $a['id'] = trim((string) $a['id']);
        $a['tenant_id'] = trim((string) $a['tenant_id']);
        $a['doc_id'] = $a['doc_id'] === null ? null : trim((string) $a['doc_id']);
        $a['extracted'] = $a['extracted'] === null ? null : json_decode((string) $a['extracted'], true);
        $a['flags'] = self::fromPgArray((string) $a['flags']);

        return $a;
    }

    public function recent(int $limit): array
    {
        return array_map(fn ($r) => ['id' => trim((string) $r->id), 'flags' => self::fromPgArray((string) $r->flags)] + (array) $r,
            DB::select('select id, file_name, status, error, credits, flags, doc_id, created_at from bill_scans order by created_at desc limit ?', [min(100, max(1, $limit))]));
    }

    /** @param list<string> $v */
    private static function pgArray(array $v): string
    {
        return '{'.implode(',', array_map(fn ($s) => '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $s).'"', $v)).'}';
    }

    /** @return list<string> */
    private static function fromPgArray(string $s): array
    {
        $s = trim($s, '{}');

        return $s === '' ? [] : array_map(fn ($x) => trim($x, '"'), explode(',', $s));
    }
}
