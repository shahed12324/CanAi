<?php

declare(strict_types=1);

namespace App\Skills;

use App\Ai\ProviderCallException;
use App\Assist\AssistEngine;
use App\Chat\CreditPricing;
use App\Chat\ModelPicker;
use App\Chat\TokenEstimator;
use App\Credits\CreditException;
use App\Credits\CreditLedger;

/** One metered model call (reserve → call → settle/release), the same invariant as the assistant: every reservation ends in exactly one settle or release. */
final class PaidCompletion
{
    public function __construct(private readonly ModelPicker $models, private readonly AssistEngine $engine, private readonly CreditLedger $credits, private readonly CreditPricing $pricing) {}

    /** @param list<array{role: string, content: string}> $messages @return array{status: string, text: string}  status: ok|no_model|no_credits|provider|failed */
    public function run(string $tenantId, string $userId, string $source, array $messages, int $maxOut, string $requestId): array
    {
        $model = $this->models->pick($tenantId, null);
        if ($model === null) {
            return ['status' => 'no_model', 'text' => ''];
        }
        $mult = $model['credit_multiplier'] ?? null;
        $maxOut = min($maxOut, max(300, (int) ($model['max_output'] ?? $maxOut)));
        $est = TokenEstimator::estimate(implode("\n", array_column($messages, 'content')));
        try {
            $reservation = $this->credits->reserve($tenantId, $this->pricing->reserveAmount($est, $maxOut, $mult), $source.':'.$requestId, $requestId)['reservation_id'];
        } catch (CreditException $e) {
            return ['status' => $e->errorCode === 'insufficient_credits' ? 'no_credits' : 'failed', 'text' => ''];
        } catch (\Throwable $e) {
            self::report($e);

            return ['status' => 'failed', 'text' => ''];
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
                $this->credits->release($tenantId, $reservation, $source.'-release:'.$requestId);
            } catch (\Throwable $e) {
                self::report($e);
            }

            return ['status' => $status, 'text' => ''];
        }
        try {
            $this->credits->settle($tenantId, $reservation, $this->pricing->cost(max(0, (int) ($answer['input_tokens'] ?? $est)), max(0, (int) ($answer['output_tokens'] ?? 0)), $mult), $source.'-settle:'.$requestId, [
                'source' => $source, 'user_id' => $userId, 'request_id' => $requestId, 'provider_id' => $model['provider_id'], 'model_id' => $model['id'],
                'input_tokens' => max(0, (int) ($answer['input_tokens'] ?? $est)), 'output_tokens' => max(0, (int) ($answer['output_tokens'] ?? 0)),
            ]);
        } catch (\Throwable $e) {
            self::report($e);   // credit_release_stale() returns the hold
        }

        return ['status' => 'ok', 'text' => (string) ($answer['text'] ?? '')];
    }

    private static function report(\Throwable $e): void
    {
        if (function_exists('report')) {
            report($e);
        }
    }
}
