<?php

declare(strict_types=1);

namespace App\Assist;

use App\Ai\ProviderCallException;
use App\Chat\CreditPricing;
use App\Chat\ModelPicker;
use App\Chat\TokenEstimator;
use App\Credits\CreditException;
use App\Credits\CreditLedger;

/**
 * Meeting minutes, email summaries and email drafts from text the person pastes. Nothing is stored and nothing is sent.
 * Money invariant: every reservation ends in exactly one settle or release; an unreadable answer is still charged (the provider was paid).
 */
final class AssistService
{
    private const MAX_OUTPUT = 2000;

    public function __construct(
        private readonly ModelPicker $models,
        private readonly AssistEngine $engine,
        private readonly CreditLedger $credits,
        private readonly CreditPricing $pricing,
    ) {}

    /** @return array{status: string, result: ?array<string, mixed>} status: ok|bad_mode|empty|too_long|no_model|no_credits|provider|unreadable|failed */
    public function run(string $tenantId, string $userId, string $mode, string $text, string $points, string $tone, string $today, string $requestId): array
    {
        $fail = fn (string $s) => ['status' => $s, 'result' => null];
        if (! isset(AssistPrompt::MODES[$mode])) {
            return $fail('bad_mode');
        }
        $clean = fn (string $s) => trim((string) preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/u', '', $s));
        $text = $clean($text);
        $points = $clean($points);
        if ($mode === 'draft' ? ($text === '' && $points === '') : $text === '') {
            return $fail('empty');
        }
        $limit = $mode === 'meeting' ? AssistPrompt::MAX['meeting'] : AssistPrompt::MAX['email'];
        if (mb_strlen($text) > $limit || mb_strlen($points) > AssistPrompt::MAX['points']) {
            return $fail('too_long');
        }
        $model = $this->models->pick($tenantId, null);
        if ($model === null) {
            return $fail('no_model');
        }
        $mult = $model['credit_multiplier'] ?? null;
        $maxOut = min(self::MAX_OUTPUT, max(300, (int) ($model['max_output'] ?? self::MAX_OUTPUT)));
        $messages = AssistPrompt::messages($mode, $text, $today, $points, $tone);
        $est = TokenEstimator::estimate($messages[0]['content'].$messages[1]['content']);
        try {
            $reservation = $this->credits->reserve($tenantId, $this->pricing->reserveAmount($est, $maxOut, $mult), 'assist:'.$requestId, $requestId)['reservation_id'];
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
        } catch (ProviderCallException $e) {
            $status = 'provider';
        } catch (\Throwable $e) {
            self::report($e);
        }
        if ($answer === null) {
            try {
                $this->credits->release($tenantId, $reservation, 'assist-release:'.$requestId);
            } catch (\Throwable $e) {
                self::report($e);
            }

            return $fail($status);
        }
        $in = max(0, (int) ($answer['input_tokens'] ?? $est));
        $out = max(0, (int) ($answer['output_tokens'] ?? 0));
        try {
            $this->credits->settle($tenantId, $reservation, $this->pricing->cost($in, $out, $mult), 'assist-settle:'.$requestId, [
                'source' => 'assist', 'user_id' => $userId, 'request_id' => $requestId, 'provider_id' => $model['provider_id'], 'model_id' => $model['id'], 'input_tokens' => $in, 'output_tokens' => $out,
            ]);
        } catch (\Throwable $e) {
            self::report($e);     // the hold is returned by credit_release_stale()
        }
        $result = AssistResult::parse($mode, (string) $answer['text']);

        return $result === null ? $fail('unreadable') : ['status' => 'ok', 'result' => $result];
    }

    private static function report(\Throwable $e): void
    {
        if (function_exists('report')) {
            report($e);
        }
    }
}
