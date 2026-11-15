<?php

declare(strict_types=1);

namespace App\Social;

use App\Ai\ProviderCallException;
use App\Assist\AssistEngine;
use App\Chat\CreditPricing;
use App\Chat\ModelPicker;
use App\Chat\TokenEstimator;
use App\Credits\CreditException;
use App\Credits\CreditLedger;

/**
 * Writes a caption + hashtags from a short subject.  Nothing is stored; the person edits the result in the form.
 * Money invariant: the reservation ends in exactly one settle (the provider answered, even if unreadable) or one release (no answer).
 */
final class SocialCaptionService
{
    private const MAX_OUTPUT = 1200;

    public function __construct(
        private readonly ModelPicker $models,
        private readonly AssistEngine $engine,
        private readonly CreditLedger $credits,
        private readonly CreditPricing $pricing,
    ) {}

    /** @return array{status: string, result: ?array{caption: string, hashtags: list<string>}} status: ok|empty|brief_long|bad_platform|bad_tone|no_model|no_credits|provider|unreadable|failed */
    public function write(string $tenantId, string $userId, string $brief, string $platform, string $tone, string $requestId): array
    {
        $fail = fn (string $s) => ['status' => $s, 'result' => null];
        $brief = trim((string) preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/u', '', $brief));
        if ($brief === '') {
            return $fail('empty');
        }
        if (mb_strlen($brief) > SocialCaptionPrompt::MAX_BRIEF) {
            return $fail('brief_long');
        }
        if (! isset(SocialText::PLATFORMS[$platform])) {
            return $fail('bad_platform');
        }
        if (! isset(SocialText::TONES[$tone])) {
            return $fail('bad_tone');
        }
        $model = $this->models->pick($tenantId, null);
        if ($model === null) {
            return $fail('no_model');
        }
        $mult = $model['credit_multiplier'] ?? null;
        $maxOut = min(self::MAX_OUTPUT, max(400, (int) ($model['max_output'] ?? self::MAX_OUTPUT)));
        $messages = SocialCaptionPrompt::messages($brief, $platform, $tone);
        $est = TokenEstimator::estimate($messages[0]['content'].$messages[1]['content']);
        try {
            $reservation = $this->credits->reserve($tenantId, $this->pricing->reserveAmount($est, $maxOut, $mult), 'scap:'.$requestId, $requestId)['reservation_id'];
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
                $this->credits->release($tenantId, $reservation, 'scap-release:'.$requestId);
            } catch (\Throwable $e) {
                self::report($e);
            }

            return $fail($status);
        }
        $in = max(0, (int) ($answer['input_tokens'] ?? $est));
        $out = max(0, (int) ($answer['output_tokens'] ?? 0));
        try {
            $this->credits->settle($tenantId, $reservation, $this->pricing->cost($in, $out, $mult), 'scap-settle:'.$requestId, [
                'source' => 'assist', 'user_id' => $userId, 'request_id' => $requestId, 'provider_id' => $model['provider_id'], 'model_id' => $model['id'], 'input_tokens' => $in, 'output_tokens' => $out,
            ]);
        } catch (\Throwable $e) {
            self::report($e);     // the hold is returned by credit_release_stale()
        }
        $r = SocialCaption::parse((string) $answer['text'], $platform);

        return $r === null ? $fail('unreadable') : ['status' => 'ok', 'result' => $r];
    }

    private static function report(\Throwable $e): void
    {
        if (function_exists('report')) {
            report($e);
        }
    }
}
