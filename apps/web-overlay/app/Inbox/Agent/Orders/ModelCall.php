<?php

declare(strict_types=1);

namespace App\Inbox\Agent\Orders;

use App\Ai\ProviderCallException;
use App\Chat\ChatEngine;
use App\Chat\CreditPricing;
use App\Chat\ModelPicker;
use App\Chat\TokenEstimator;
use App\Credits\CreditException;
use App\Credits\CreditLedger;
use Closure;

/** One model call with the credit rule: reserve first, settle only a usable answer, release on every other path. Never throws. */
final class ModelCall
{
    public function __construct(private readonly ModelPicker $models, private readonly ChatEngine $engine, private readonly CreditLedger $credits, private readonly CreditPricing $pricing, private readonly int $maxOutput = 500) {}

    /**
     * @param  list<array{role: string, content: string}>  $messages
     * @param  Closure(string): bool  $accept  is this answer usable (worth paying for)?
     * @return array{ok: bool, text: string, fail: ?string}  fail = no_model | credits | engine | unusable
     */
    public function run(string $tenantId, array $messages, Closure $accept): array
    {
        $model = $this->models->pick($tenantId, null);
        if ($model === null) {
            return ['ok' => false, 'text' => '', 'fail' => 'no_model'];
        }
        $inEst = TokenEstimator::estimate(implode("\n", array_column($messages, 'content')));
        $max = max(64, min($model['max_output'] ?? $this->maxOutput, $this->maxOutput));
        $mult = $model['credit_multiplier'] ?? null;
        $id = bin2hex(random_bytes(13));
        try {
            $reservation = $this->credits->reserve($tenantId, $this->pricing->reserveAmount($inEst, $max, $mult), 'inbox-order:'.$id, $id)['reservation_id'];
        } catch (CreditException) {
            return ['ok' => false, 'text' => '', 'fail' => 'credits'];
        } catch (\Throwable $e) {
            self::report($e);

            return ['ok' => false, 'text' => '', 'fail' => 'engine'];
        }
        $text = '';
        $inTok = null;
        $outTok = null;
        $settled = false;
        try {
            try {
                foreach ($this->engine->stream($model['provider_id'], $model['model_key'], $messages, $tenantId, null, $max) as $ev) {
                    $text .= $ev['text'];
                    $inTok = $ev['input_tokens'] ?? $inTok;
                    $outTok = $ev['output_tokens'] ?? $outTok;
                }
            } catch (ProviderCallException) {
                return ['ok' => false, 'text' => '', 'fail' => 'engine'];
            } catch (\Throwable $e) {
                self::report($e);

                return ['ok' => false, 'text' => '', 'fail' => 'engine'];
            }
            if (! $accept($text)) {
                return ['ok' => false, 'text' => $text, 'fail' => 'unusable'];
            }
            try {
                $this->credits->settle($tenantId, $reservation, $this->pricing->cost($inTok ?? $inEst, $outTok ?? TokenEstimator::estimate($text), $mult), 'inbox-order-settle:'.$id, [
                    'source' => 'inbox', 'user_id' => null, 'request_id' => $id, 'provider_id' => $model['provider_id'], 'model_id' => $model['id'],
                    'input_tokens' => $inTok ?? $inEst, 'output_tokens' => $outTok ?? TokenEstimator::estimate($text),
                ]);
            } catch (\Throwable $e) {
                self::report($e);
            }
            $settled = true;

            return ['ok' => true, 'text' => $text, 'fail' => null];
        } finally {
            if (! $settled) {
                try {
                    $this->credits->release($tenantId, $reservation, 'inbox-order-release:'.$id);
                } catch (\Throwable $e) {
                    self::report($e);
                }
            }
        }
    }

    private static function report(\Throwable $e): void
    {
        if (function_exists('report')) {
            report($e);
        }
    }
}
