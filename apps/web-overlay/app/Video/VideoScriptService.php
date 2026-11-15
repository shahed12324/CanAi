<?php

declare(strict_types=1);

namespace App\Video;

use App\Ai\ProviderCallException;
use App\Assist\AssistEngine;
use App\Chat\CreditPricing;
use App\Chat\ModelPicker;
use App\Chat\TokenEstimator;
use App\Credits\CreditException;
use App\Credits\CreditLedger;

/**
 * Writes the scenes and voice-over of a video from a short brief.  Nothing is stored; the person reviews and edits the result in the form.
 * Money invariant: the reservation ends in exactly one settle (the provider answered, even if the answer was unreadable) or one release (no answer).
 */
final class VideoScriptService
{
    private const MAX_OUTPUT = 2000;

    public function __construct(
        private readonly ModelPicker $models,
        private readonly AssistEngine $engine,
        private readonly CreditLedger $credits,
        private readonly CreditPricing $pricing,
    ) {}

    /** @return array{status: string, script: ?array<string, mixed>} status: ok|empty|brief_long|bad_count|no_model|no_credits|provider|unreadable|failed */
    public function write(string $tenantId, string $userId, string $brief, int $scenes, string $requestId): array
    {
        $fail = fn (string $s) => ['status' => $s, 'script' => null];
        $brief = trim((string) preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/u', '', $brief));
        if ($brief === '') {
            return $fail('empty');
        }
        if (mb_strlen($brief) > VideoScriptPrompt::MAX_BRIEF) {
            return $fail('brief_long');
        }
        if ($scenes < 1 || $scenes > VideoService::MAX_SCENES) {
            return $fail('bad_count');
        }
        $model = $this->models->pick($tenantId, null);
        if ($model === null) {
            return $fail('no_model');
        }
        $mult = $model['credit_multiplier'] ?? null;
        $maxOut = min(self::MAX_OUTPUT, max(500, (int) ($model['max_output'] ?? self::MAX_OUTPUT)));
        $messages = VideoScriptPrompt::messages($brief, $scenes);
        $est = TokenEstimator::estimate($messages[0]['content'].$messages[1]['content']);
        try {
            $reservation = $this->credits->reserve($tenantId, $this->pricing->reserveAmount($est, $maxOut, $mult), 'vscript:'.$requestId, $requestId)['reservation_id'];
        } catch (CreditException $e) {
            return $fail($e->errorCode === 'insufficient_credits' ? 'no_credits' : 'failed');
        } catch (\Throwable $e) {
            self::report($e);

            return $fail('failed');
        }
        $answer = null;
        $status = 'failed';
        try {
            $answer = $this->engine->complete($model['provider_id'], $model['model_key'], $messages, $tenantId, $userId, $maxOut);
        } catch (ProviderCallException) {
            $status = 'provider';
        } catch (\Throwable $e) {
            self::report($e);
        }
        if ($answer === null) {
            try {
                $this->credits->release($tenantId, $reservation, 'vscript-release:'.$requestId);
            } catch (\Throwable $e) {
                self::report($e);
            }

            return $fail($status);
        }
        $in = max(0, (int) ($answer['input_tokens'] ?? $est));
        $out = max(0, (int) ($answer['output_tokens'] ?? 0));
        try {
            $this->credits->settle($tenantId, $reservation, $this->pricing->cost($in, $out, $mult), 'vscript-settle:'.$requestId, [
                'source' => 'assist', 'user_id' => $userId, 'request_id' => $requestId, 'provider_id' => $model['provider_id'], 'model_id' => $model['id'], 'input_tokens' => $in, 'output_tokens' => $out,
            ]);
        } catch (\Throwable $e) {
            self::report($e);     // the hold is returned by credit_release_stale()
        }
        $script = VideoScript::parse((string) $answer['text'], $scenes);

        return $script === null ? $fail('unreadable') : ['status' => 'ok', 'script' => $script];
    }

    private static function report(\Throwable $e): void
    {
        if (function_exists('report')) {
            report($e);
        }
    }
}
