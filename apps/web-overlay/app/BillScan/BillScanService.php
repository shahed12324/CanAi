<?php

declare(strict_types=1);

namespace App\BillScan;

use App\Ai\ProviderCallException;
use App\Chat\CreditPricing;
use App\Credits\CreditException;
use App\Credits\CreditLedger;

/**
 * Upload -> scan -> draft. The model only READS the bill; nothing is posted here (a person reviews and confirms in BillDraft/bill_scan_post).
 *
 * Money invariant (same as chat): every reservation ends in exactly one settle or release. Once the engine has answered, the provider has been
 * paid, so the answer is settled even when we then mark the scan failed (unreadable / bad draft). If settle itself throws, the reservation is
 * left for credit_release_stale(), so the customer is never charged for nothing. A re-uploaded identical file is a 'duplicate' and costs nothing.
 */
final class BillScanService
{
    private const EST_INPUT = 8000;   // images + prompt, worst case

    private const MAX_OUTPUT = 2000;

    public function __construct(
        private readonly BillScanRepo $repo,
        private readonly BillScanEngine $engine,
        private readonly BillFileStore $files,
        private readonly VisionPicker $models,
        private readonly CreditLedger $credits,
        private readonly CreditPricing $pricing,
    ) {}

    /**
     * @param  list<array{code: string, name: string}>  $accounts  offered to the model; it may only choose from these
     * @return array{status: string, scan_id: ?string, error: ?string} status: ready | duplicate | failed | rejected
     */
    public function scan(string $tenantId, string $userId, string $name, string $bytes, array $accounts): array
    {
        $v = BillFile::validate($bytes);
        if ($v['mime'] === null) {
            return ['status' => 'rejected', 'scan_id' => null, 'error' => $v['error']];
        }
        $mime = $v['mime'];
        $s = $this->repo->start($tenantId, $userId, BillFile::safeName($name), $mime, strlen($bytes), hash('sha256', $bytes));
        if ($s['result'] === 'duplicate') {
            return ['status' => 'duplicate', 'scan_id' => $s['scan_id'], 'error' => null];
        }
        if ($s['result'] !== 'ok' || $s['scan_id'] === null) {
            return ['status' => 'rejected', 'scan_id' => null, 'error' => 'The file could not be accepted.'];
        }
        $id = $s['scan_id'];

        $model = $this->models->pick($tenantId);
        if ($model === null) {
            return $this->failed($id, 'no_model', null, 0, 0, '0.0000');
        }
        $mult = $model['credit_multiplier'] ?? null;

        try {
            $reservation = $this->credits->reserve($tenantId, $this->pricing->reserveAmount(self::EST_INPUT, self::MAX_OUTPUT, $mult), 'billscan:'.$id, $id)['reservation_id'];
        } catch (CreditException $e) {
            return $this->failed($id, $e->errorCode === 'insufficient_credits' ? 'insufficient_credits' : 'credits', null, 0, 0, '0.0000');
        } catch (\Throwable $e) {
            self::report($e);

            return $this->failed($id, 'internal', null, 0, 0, '0.0000');
        }

        $answer = null;
        $error = 'internal';
        try {
            $this->files->put($tenantId, $id, $mime, $bytes);
            $answer = $this->engine->scan($model['provider_id'], $model['model_key'], $mime, $bytes, array_slice($accounts, 0, 80), $tenantId, $userId);
        } catch (ProviderCallException $e) {
            $error = 'provider:'.mb_substr($e->errorCode, 0, 40);
        } catch (\Throwable $e) {
            self::report($e);
            $error = 'engine_error';
        }

        $status = is_array($answer) ? (string) ($answer['status'] ?? '') : '';
        if (! in_array($status, ['ok', 'unreadable'], true)) {
            // Nothing was read (bad file / provider down / our own error): no provider cost known -> give the hold back.
            $this->releaseQuietly($tenantId, $reservation, $id);
            $this->dropFile($tenantId, $id, $mime);

            return $this->failed($id, $status === 'unsupported' ? 'unsupported:'.mb_substr((string) ($answer['reason'] ?? ''), 0, 30) : (is_array($answer) ? 'engine_error' : $error), $model['name'], 0, 0, '0.0000');
        }

        $in = max(0, (int) ($answer['input_tokens'] ?? self::EST_INPUT));
        $out = max(0, (int) ($answer['output_tokens'] ?? 0));
        $charged = '0.0000';
        try {
            $charged = $this->credits->settle($tenantId, $reservation, $this->pricing->cost($in, $out, $mult), 'billscan-settle:'.$id, [
                'source' => 'bill_scan', 'user_id' => $userId, 'request_id' => $id, 'provider_id' => $model['provider_id'], 'model_id' => $model['id'], 'input_tokens' => $in, 'output_tokens' => $out,
            ])['charged'];
        } catch (\Throwable $e) {
            self::report($e);   // reservation stays held; credit_release_stale() returns it
        }

        if ($status === 'unreadable') {
            $this->dropFile($tenantId, $id, $mime);

            return $this->failed($id, 'unreadable', $model['name'], $in, $out, $charged);
        }
        $data = $answer['data'] ?? null;
        $flags = array_values(array_filter(array_map('strval', (array) ($answer['flags'] ?? []))));
        $res = is_array($data) ? $this->repo->ready($id, $data, $flags, (string) ($answer['model'] ?? $model['model_key']), $in, $out, $charged) : 'bad_input';
        if ($res !== 'ok') {
            $this->dropFile($tenantId, $id, $mime);

            return $this->failed($id, 'bad_draft', $model['name'], $in, $out, $charged);
        }

        return ['status' => 'ready', 'scan_id' => $id, 'error' => null];
    }

    /** @return array{status: string, scan_id: ?string, error: ?string} */
    private function failed(string $id, string $code, ?string $model, int $in, int $out, string $credits): array
    {
        try {
            $this->repo->fail($id, $code, $model, $in, $out, $credits);
        } catch (\Throwable $e) {
            self::report($e);   // the 10-minute stale rule in bill_scan_start closes it
        }

        return ['status' => 'failed', 'scan_id' => $id, 'error' => $code];
    }

    private function releaseQuietly(string $tenantId, string $reservation, string $id): void
    {
        try {
            $this->credits->release($tenantId, $reservation, 'billscan-release:'.$id);
        } catch (\Throwable $e) {
            self::report($e);
        }
    }

    private function dropFile(string $tenantId, string $id, string $mime): void
    {
        try {
            $this->files->delete($tenantId, $id, $mime);
        } catch (\Throwable $e) {
            self::report($e);
        }
    }

    private static function report(\Throwable $e): void
    {
        if (function_exists('report')) {
            report($e);
        }
    }
}
