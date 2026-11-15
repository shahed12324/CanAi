<?php

declare(strict_types=1);

namespace App\GoogleAds;

use App\Ai\ProviderCallException;
use App\Assist\AssistEngine;
use App\Chat\CreditPricing;
use App\Chat\ModelPicker;
use App\Chat\TokenEstimator;
use App\Credits\CreditException;
use App\Credits\CreditLedger;
use App\Social\SocialText;

/**
 * Suggests Google Search ad words (headlines, descriptions, keywords) from a short brief.  Nothing is stored or sent to Google; the person edits it into a draft.
 * Money invariant: exactly one settle (the provider answered, even if unreadable) or one release (no answer); nothing is reserved on bad input / no model.
 */
final class GadsCopyService
{
    private const MAX_OUTPUT = 1500;

    public function __construct(private readonly ModelPicker $models, private readonly AssistEngine $engine, private readonly CreditLedger $credits, private readonly CreditPricing $pricing) {}

    /** @return array{status: string, result: ?array<string, mixed>} status: ok|empty|brief_long|bad_tone|no_model|no_credits|provider|unreadable|failed */
    public function suggest(string $tenantId, string $userId, string $brief, string $tone, string $requestId): array
    {
        $fail = fn (string $s) => ['status' => $s, 'result' => null];
        $brief = trim((string) preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/u', '', $brief));
        if ($brief === '') {
            return $fail('empty');
        }
        if (mb_strlen($brief) > 1500) {
            return $fail('brief_long');
        }
        if (! isset(SocialText::TONES[$tone])) {
            return $fail('bad_tone');
        }
        $model = $this->models->pick($tenantId, null);
        if ($model === null) {
            return $fail('no_model');
        }
        $mult = $model['credit_multiplier'] ?? null;
        $maxOut = min(self::MAX_OUTPUT, max(800, (int) ($model['max_output'] ?? self::MAX_OUTPUT)));
        $messages = GadsCopyPrompt::messages($brief, SocialText::TONES[$tone]);
        $est = TokenEstimator::estimate($messages[0]['content'].$messages[1]['content']);
        try {
            $reservation = $this->credits->reserve($tenantId, $this->pricing->reserveAmount($est, $maxOut, $mult), 'gadcopy:'.$requestId, $requestId)['reservation_id'];
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
                $this->credits->release($tenantId, $reservation, 'gadcopy-release:'.$requestId);
            } catch (\Throwable $e) {
                self::report($e);
            }

            return $fail($status);
        }
        $in = max(0, (int) ($answer['input_tokens'] ?? $est));
        $out = max(0, (int) ($answer['output_tokens'] ?? 0));
        try {
            $this->credits->settle($tenantId, $reservation, $this->pricing->cost($in, $out, $mult), 'gadcopy-settle:'.$requestId, [
                'source' => 'assist', 'user_id' => $userId, 'request_id' => $requestId, 'provider_id' => $model['provider_id'], 'model_id' => $model['id'], 'input_tokens' => $in, 'output_tokens' => $out,
            ]);
        } catch (\Throwable $e) {
            self::report($e);     // the hold is returned by credit_release_stale()
        }
        $r = GadsCopy::parse((string) $answer['text']);

        return $r === null ? $fail('unreadable') : ['status' => 'ok', 'result' => $r];
    }

    private static function report(\Throwable $e): void
    {
        if (function_exists('report')) {
            report($e);
        }
    }
}
