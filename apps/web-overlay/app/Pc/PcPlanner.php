<?php

declare(strict_types=1);

namespace App\Pc;

use App\Ai\ProviderCallException;
use App\Chat\CreditPricing;
use App\Chat\ModelPicker;
use App\Credits\CreditException;
use App\Credits\CreditLedger;

/**
 * "Tell the computer what you want": the model drafts a plan, the plan goes through the SAME submit path as a hand-pasted one (database rules, person's approval, phone for risky steps).
 * The model can never run anything: its output is only a draft that waits for a person.
 *
 * Money invariant (same as chat / office): every reservation ends in exactly one settle or release; a run that reached the model IS charged even if the draft was unusable.
 */
final class PcPlanner
{
    private const EST_INPUT = 2500;

    private const MAX_OUTPUT = 3000;

    public function __construct(
        private readonly ModelPicker $models,
        private readonly PlanEngine $engine,
        private readonly PcRepo $devices,
        private readonly PcPlanRepo $plans,
        private readonly CreditLedger $credits,
        private readonly CreditPricing $pricing,
        private readonly ?\App\Mobile\ApprovalAlert $alert = null,
    ) {}

    /** @return array{status: string, reason: ?string, review: mixed} status: ok|bad_goal|no_policy|no_model|no_credits|provider|refused|invalid|rejected|failed */
    public function draft(string $tenantId, string $userId, string $deviceId, string $goal, string $requestId): array
    {
        $fail = fn (string $s, ?string $why = null) => ['status' => $s, 'reason' => $why, 'review' => null];
        $goal = trim((string) preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/u', '', $goal));
        if (mb_strlen($goal) < 3 || mb_strlen($goal) > 2000) {
            return $fail('bad_goal');
        }
        $policy = $this->devices->policy($tenantId, $deviceId);
        if ($policy === null || $policy['roots'] === []) {
            return $fail('no_policy');
        }
        $model = $this->models->pick($tenantId, null);
        if ($model === null) {
            return $fail('no_model');
        }
        $mult = $model['credit_multiplier'] ?? null;
        $maxOut = min(self::MAX_OUTPUT, max(500, (int) ($model['max_output'] ?? self::MAX_OUTPUT)));
        try {
            // two model attempts at most inside the engine -> reserve for two
            $reservation = $this->credits->reserve($tenantId, $this->pricing->reserveAmount(self::EST_INPUT * 2, $maxOut * 2, $mult), 'pcplan:'.$requestId, $requestId)['reservation_id'];
        } catch (CreditException $e) {
            return $fail($e->errorCode === 'insufficient_credits' ? 'no_credits' : 'failed');
        } catch (\Throwable $e) {
            self::report($e);

            return $fail('failed');
        }

        $answer = null;
        $status = 'failed';
        try {
            $answer = $this->engine->draft($model['provider_id'], $model['model_key'], $goal, $policy['roots'], $this->kinds($policy), $tenantId, $userId, array_values((array) ($policy['apps'] ?? [])), array_values((array) ($policy['hosts'] ?? [])));
        } catch (ProviderCallException) {
            $status = 'provider';
        } catch (\Throwable $e) {
            self::report($e);
        }
        if ($answer === null) {
            try {
                $this->credits->release($tenantId, $reservation, 'pcplan-release:'.$requestId);
            } catch (\Throwable $e) {
                self::report($e);
            }

            return $fail($status);
        }
        $in = max(0, (int) ($answer['input_tokens'] ?? self::EST_INPUT));
        $out = max(0, (int) ($answer['output_tokens'] ?? 0));
        try {
            $this->credits->settle($tenantId, $reservation, $this->pricing->cost($in, $out, $mult), 'pcplan-settle:'.$requestId, [
                'source' => 'pcplan', 'user_id' => $userId, 'request_id' => $requestId, 'provider_id' => $model['provider_id'], 'model_id' => $model['id'], 'input_tokens' => $in, 'output_tokens' => $out,
            ]);
        } catch (\Throwable $e) {
            self::report($e);     // the hold is returned by credit_release_stale()
        }

        $st = (string) ($answer['status'] ?? '');
        if ($st !== 'ok') {
            return $fail($st === 'refused' ? 'refused' : 'invalid', is_string($answer['reason'] ?? null) ? mb_substr($answer['reason'], 0, 200) : null);
        }
        $plan = $answer['plan'] ?? null;
        if (! is_array($plan) || ! isset($plan['steps']) || ! is_array($plan['steps'])) {
            return $fail('invalid');
        }
        $r = $this->plans->submit($tenantId, $userId, $deviceId, $goal, ['steps' => $plan['steps']]);   // the database has the last word
        if (($r['status'] ?? '') !== 'pending') {
            return $fail('rejected', (string) ($r['reason'] ?? 'bad_input'));
        }

        $this->alert?->computerWaiting($tenantId, $userId, true);     // best effort, never throws

        return ['status' => 'ok', 'reason' => null, 'review' => $answer['review'] ?? null];
    }

    /** Kinds the model may use: everything the engine knows except what this device's rules deny (the database still checks every step). */
    private function kinds(array $policy): array
    {
        $deny = array_keys(array_filter((array) ($policy['modes'] ?? []), fn ($m) => $m === 'deny'));

        return array_values(array_diff([...array_keys(PcService::KINDS), 'verify_file'], $deny));
    }

    private static function report(\Throwable $e): void
    {
        if (function_exists('report')) {
            report($e);
        }
    }
}
