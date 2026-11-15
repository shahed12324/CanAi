<?php

declare(strict_types=1);

namespace App\Office;

use App\Ai\ProviderCallException;
use App\Chat\CreditPricing;
use App\Chat\ModelPicker;
use App\Credits\CreditException;
use App\Credits\CreditLedger;

/**
 * "Make me a document / sheet / deck". The model writes a structured spec; the spec is checked, saved, and turned into a file on download (no model, no charge).
 *
 * Money invariant (same as chat / memory / vision): every reservation ends in exactly one settle or release.
 * A run that reached the model but gave an unusable answer IS charged - the provider was paid.
 */
final class OfficeService
{
    private const EST_INPUT = 1500;

    private const MAX_OUTPUT = 6000;

    public function __construct(
        private readonly ModelPicker $models,
        private readonly OfficeEngine $engine,
        private readonly OfficeRepo $repo,
        private readonly CreditLedger $credits,
        private readonly CreditPricing $pricing,
    ) {}

    /** @return array{status: string, id: ?string, title: ?string} status: ok|empty_request|bad_kind|no_model|no_credits|provider|unreadable|limit|failed */
    public function create(string $tenantId, string $userId, string $kind, string $request, string $fileId, string $requestId): array
    {
        $fail = fn (string $s) => ['status' => $s, 'id' => null, 'title' => null];
        if (! isset(OfficeSpec::KINDS[$kind])) {
            return $fail('bad_kind');
        }
        $request = trim((string) preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/u', '', $request));
        if ($request === '') {
            return $fail('empty_request');
        }
        if ($this->repo->count($userId) >= 100) {
            return $fail('limit');
        }
        $model = $this->models->pick($tenantId, null);
        if ($model === null) {
            return $fail('no_model');
        }
        $mult = $model['credit_multiplier'] ?? null;
        $maxOut = min(self::MAX_OUTPUT, max(500, (int) ($model['max_output'] ?? self::MAX_OUTPUT)));
        try {
            $reservation = $this->credits->reserve($tenantId, $this->pricing->reserveAmount(self::EST_INPUT, $maxOut, $mult), 'docgen:'.$requestId, $requestId)['reservation_id'];
        } catch (CreditException $e) {
            return $fail($e->errorCode === 'insufficient_credits' ? 'no_credits' : 'failed');
        } catch (\Throwable $e) {
            self::report($e);

            return $fail('failed');
        }

        $answer = null;
        $status = 'failed';
        try {
            $answer = $this->engine->complete($model['provider_id'], $model['model_key'], OfficePrompt::messages($kind, $request), $tenantId, $userId, $maxOut);
        } catch (ProviderCallException $e) {
            $status = 'provider';
        } catch (\Throwable $e) {
            self::report($e);
        }
        if ($answer === null) {
            try {
                $this->credits->release($tenantId, $reservation, 'docgen-release:'.$requestId);
            } catch (\Throwable $e) {
                self::report($e);
            }

            return $fail($status);
        }
        $in = max(0, (int) ($answer['input_tokens'] ?? self::EST_INPUT));
        $out = max(0, (int) ($answer['output_tokens'] ?? 0));
        try {
            $this->credits->settle($tenantId, $reservation, $this->pricing->cost($in, $out, $mult), 'docgen-settle:'.$requestId, [
                'source' => 'docgen', 'user_id' => $userId, 'request_id' => $requestId, 'provider_id' => $model['provider_id'], 'model_id' => $model['id'], 'input_tokens' => $in, 'output_tokens' => $out,
            ]);
        } catch (\Throwable $e) {
            self::report($e);     // the hold is returned by credit_release_stale()
        }

        $spec = OfficeSpec::parse($kind, (string) $answer['text']);
        if ($spec === null) {
            return $fail('unreadable');
        }
        $saved = $this->repo->save($tenantId, $userId, $fileId, $kind, $spec['title'], mb_substr($request, 0, 2000), $spec);
        if ($saved !== 'ok') {
            return $fail($saved === 'limit' ? 'limit' : 'failed');
        }

        return ['status' => 'ok', 'id' => $fileId, 'title' => $spec['title']];
    }

    /** @return array{bytes: string, name: string, mime: string}|null null = no such file for this person */
    public function download(string $tenantId, string $userId, string $id): ?array
    {
        $f = $this->repo->find($userId, $id);
        if ($f === null || ! isset(OfficeSpec::KINDS[$f['kind']])) {
            return null;
        }

        return ['bytes' => $this->engine->build($f['kind'], $f['spec'], $tenantId, $userId), 'name' => OfficePrompt::fileName($f['title'], $f['kind']), 'mime' => OfficeSpec::MIME[$f['kind']]];
    }

    private static function report(\Throwable $e): void
    {
        if (function_exists('report')) {
            report($e);
        }
    }
}
