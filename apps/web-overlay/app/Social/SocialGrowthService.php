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
 * Growth analysis: the numbers are computed without AI (SocialGrowth, free); the AI only turns them into plain advice.  Read-only — it changes nothing.
 * Money invariant: exactly one settle (provider answered, even if unreadable) or one release (no answer); nothing is reserved when there is too little data.
 */
final class SocialGrowthService
{
    private const MAX_OUTPUT = 900;

    public function __construct(
        private readonly SocialRepo $repo,
        private readonly ModelPicker $models,
        private readonly AssistEngine $engine,
        private readonly CreditLedger $credits,
        private readonly CreditPricing $pricing,
        private readonly SocialService $social,
    ) {}

    /** @return array{n: int, avg: float, enough: bool, groups: array<string, array<string, array{n: int, avg: float}>>, best: array<string, ?string>} */
    public function numbers(int $days): array
    {
        return SocialGrowth::analyze($this->repo->posted($days), $this->social->zone());
    }

    /** @return array{status: string, advice: ?array{summary: string, do: list<string>, avoid: list<string>, test_next: list<string>}} status: ok|too_little|goal_long|no_model|no_credits|provider|unreadable|failed */
    public function advise(string $tenantId, string $userId, int $days, string $goal, string $requestId): array
    {
        $fail = fn (string $s) => ['status' => $s, 'advice' => null];
        $goal = trim((string) preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/u', '', $goal));
        if (mb_strlen($goal) > 500) {
            return $fail('goal_long');
        }
        try {
            $numbers = $this->numbers($days);
        } catch (\Throwable $e) {
            self::report($e);

            return $fail('failed');
        }
        if (! $numbers['enough']) {
            return $fail('too_little');
        }
        $model = $this->models->pick($tenantId, null);
        if ($model === null) {
            return $fail('no_model');
        }
        $mult = $model['credit_multiplier'] ?? null;
        $maxOut = min(self::MAX_OUTPUT, max(500, (int) ($model['max_output'] ?? self::MAX_OUTPUT)));
        $messages = SocialGrowthPrompt::messages($numbers, $goal);
        $est = TokenEstimator::estimate($messages[0]['content'].$messages[1]['content']);
        try {
            $reservation = $this->credits->reserve($tenantId, $this->pricing->reserveAmount($est, $maxOut, $mult), 'sgrow:'.$requestId, $requestId)['reservation_id'];
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
                $this->credits->release($tenantId, $reservation, 'sgrow-release:'.$requestId);
            } catch (\Throwable $e) {
                self::report($e);
            }

            return $fail($status);
        }
        $in = max(0, (int) ($answer['input_tokens'] ?? $est));
        $out = max(0, (int) ($answer['output_tokens'] ?? 0));
        try {
            $this->credits->settle($tenantId, $reservation, $this->pricing->cost($in, $out, $mult), 'sgrow-settle:'.$requestId, [
                'source' => 'assist', 'user_id' => $userId, 'request_id' => $requestId, 'provider_id' => $model['provider_id'], 'model_id' => $model['id'], 'input_tokens' => $in, 'output_tokens' => $out,
            ]);
        } catch (\Throwable $e) {
            self::report($e);     // the hold is returned by credit_release_stale()
        }
        $advice = SocialGrowthAdvice::parse((string) $answer['text']);

        return $advice === null ? $fail('unreadable') : ['status' => 'ok', 'advice' => $advice];
    }

    private static function report(\Throwable $e): void
    {
        if (function_exists('report')) {
            report($e);
        }
    }
}
