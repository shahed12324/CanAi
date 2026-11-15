<?php

declare(strict_types=1);

namespace App\Vision;

use App\Ai\ProviderCallException;
use App\BillScan\BillFile;
use App\BillScan\VisionPicker;
use App\Chat\CreditPricing;
use App\Credits\CreditException;
use App\Credits\CreditLedger;

/**
 * One picture or PDF -> text. Nothing is kept: the bytes go to the engine and the words come back.
 *
 * Money invariant (same as chat): every reservation ends in exactly one settle or release. When the engine answers, the provider has been paid,
 * so even an "empty" answer is settled. A PDF that already carries its text costs nothing (the engine reports 0 tokens) and the hold is released.
 * If settle itself throws, the reservation is left for credit_release_stale(): the customer is never charged for nothing.
 */
final class VisionReader
{
    public const MODES = ['transcribe', 'describe'];

    private const EST_INPUT = 9000;     // up to six pages + prompt

    private const MAX_OUTPUT = 3000;

    public function __construct(
        private readonly VisionEngine $engine,
        private readonly VisionPicker $models,
        private readonly CreditLedger $credits,
        private readonly CreditPricing $pricing,
    ) {}

    /** @return array{status: string, text: ?string, pages: int, credits: string, reason: ?string}  status: ok|empty|rejected|unsupported|no_model|no_credits|provider|failed */
    public function read(string $tenantId, string $userId, string $bytes, string $mode, string $requestId): array
    {
        if (! in_array($mode, self::MODES, true)) {
            return self::res('rejected', reason: 'bad_mode');
        }
        $v = BillFile::validate($bytes);
        if ($v['mime'] === null) {
            return self::res('rejected', reason: (string) $v['error']);
        }
        $model = $this->models->pick($tenantId);
        if ($model === null) {
            return self::res('no_model');
        }
        $mult = $model['credit_multiplier'] ?? null;
        try {
            $reservation = $this->credits->reserve($tenantId, $this->pricing->reserveAmount(self::EST_INPUT, self::MAX_OUTPUT, $mult), 'vision:'.$requestId, $requestId)['reservation_id'];
        } catch (CreditException $e) {
            return self::res('no_credits');
        } catch (\Throwable $e) {
            self::report($e);

            return self::res('failed');
        }

        $answer = null;
        $status = 'failed';
        $reason = null;
        try {
            $answer = $this->engine->read($model['provider_id'], $model['model_key'], $v['mime'], $bytes, $mode, $tenantId, $userId);
        } catch (ProviderCallException $e) {
            $status = 'provider';
            $reason = mb_substr($e->errorCode, 0, 40);
        } catch (\Throwable $e) {
            self::report($e);
        }

        $got = is_array($answer) ? (string) ($answer['status'] ?? '') : '';
        if (! in_array($got, ['ok', 'empty'], true)) {
            $this->releaseQuietly($tenantId, $reservation, $requestId);
            if (is_array($answer) && $got === 'unsupported') {
                return self::res('unsupported', reason: mb_substr((string) ($answer['reason'] ?? ''), 0, 30));
            }

            return self::res($status, reason: $reason);
        }

        $in = max(0, (int) ($answer['input_tokens'] ?? 0));
        $out = max(0, (int) ($answer['output_tokens'] ?? 0));
        $charged = '0.0000';
        if ($in + $out === 0) {
            $this->releaseQuietly($tenantId, $reservation, $requestId);   // read from the PDF's own text layer: no model was used
        } else {
            try {
                $charged = $this->credits->settle($tenantId, $reservation, $this->pricing->cost($in, $out, $mult), 'vision-settle:'.$requestId, [
                    'source' => 'vision', 'user_id' => $userId, 'request_id' => $requestId, 'provider_id' => $model['provider_id'], 'model_id' => $model['id'],
                    'input_tokens' => $in, 'output_tokens' => $out,
                ])['charged'];
            } catch (\Throwable $e) {
                self::report($e);   // reservation stays held; credit_release_stale() returns it
            }
        }
        $text = $got === 'ok' && is_string($answer['text'] ?? null) && trim($answer['text']) !== '' ? (string) $answer['text'] : null;

        return ['status' => $text === null ? 'empty' : 'ok', 'text' => $text, 'pages' => max(0, (int) ($answer['pages'] ?? 0)), 'credits' => $charged, 'reason' => null];
    }

    /** @return array{status: string, text: ?string, pages: int, credits: string, reason: ?string} */
    private static function res(string $status, ?string $reason = null): array
    {
        return ['status' => $status, 'text' => null, 'pages' => 0, 'credits' => '0.0000', 'reason' => $reason];
    }

    private function releaseQuietly(string $tenantId, string $reservation, string $requestId): void
    {
        try {
            $this->credits->release($tenantId, $reservation, 'vision-release:'.$requestId);
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
