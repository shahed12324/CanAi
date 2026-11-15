<?php

declare(strict_types=1);

namespace App\Analyst;

use App\Ai\ProviderCallException;
use App\Chat\CreditPricing;
use App\Credits\CreditException;
use App\Credits\CreditLedger;

/**
 * Facts and forecast come from the books (Facts, Forecast). The AI only puts them into Bengali sentences; NarrativeCheck rejects any wording that quotes a number
 * it was not given or calls a forecast anything but an estimate, and then the fixed template is shown instead. Nothing here posts or changes any accounting data.
 *
 * Money invariant (same as chat / bill scan): every reservation ends in exactly one settle or release. Once the provider has answered it has been paid, so the answer is
 * settled even when the wording is then rejected. If settle itself throws, the reservation is left for credit_release_stale().
 */
final class AnalystService
{
    private const EST_INPUT = 2500;

    private const MAX_OUTPUT = 900;

    public function __construct(
        private readonly AnalystRepo $repo,
        private readonly AnalystEngine $engine,
        private readonly AnalystPicker $models,
        private readonly CreditLedger $credits,
        private readonly CreditPricing $pricing,
    ) {}

    /** The newest COMPLETE month as of $today ('YYYY-MM-DD', Dhaka). */
    public static function lastFullMonth(string $today): string
    {
        return Forecast::after(substr($today, 0, 7), -1);
    }

    /**
     * @param  string|null  $month  last complete month to analyse; null = the newest complete month
     * @return array{status: string, report_id: ?string, source: ?string, note: ?string} status: ok | no_data | bad_month | failed
     */
    public function analyze(string $tenantId, string $userId, ?string $month, bool $useAi, string $today, string $reportId): array
    {
        $last = self::lastFullMonth($today);
        $month ??= $last;
        if (preg_match('/\A20\d\d-(0[1-9]|1[0-2])\z/', $month) !== 1 || $month > $last) {
            return ['status' => 'bad_month', 'report_id' => null, 'source' => null, 'note' => null];
        }
        $months = Facts::months($month);
        $from = $months[0].'-01';
        $to = date('Y-m-t', (int) strtotime($month.'-01'));
        $rows = $this->repo->monthly($from, $to);
        $open = $this->repo->openItems();
        $facts = Facts::build($month, $rows, $this->repo->cashMonthly($from, $to), $this->repo->cashBalance($to), $open['receivable'], $open['payable']);
        if ($facts['history_months'] === 0) {
            return ['status' => 'no_data', 'report_id' => null, 'source' => null, 'note' => null];
        }
        $forecast = Forecast::build($facts);

        $text = null;
        $note = null;
        $model = null;
        $in = 0;
        $out = 0;
        $charged = '0.0000';
        if ($useAi) {
            [$text, $note, $model, $in, $out, $charged] = $this->askModel($tenantId, $userId, $reportId, $facts, $forecast);
        }
        $source = $text === null ? 'template' : 'ai';
        $text ??= AnalystText::template($facts, $forecast);

        $r = $this->repo->saveReport($tenantId, $userId, $reportId, $month, $facts, $forecast, $text, $source, $note, $source === 'ai' ? $model : null, $in, $out, $charged);

        return $r === 'ok' ? ['status' => 'ok', 'report_id' => $reportId, 'source' => $source, 'note' => $note] : ['status' => 'failed', 'report_id' => null, 'source' => null, 'note' => $r];
    }

    /**
     * @param  array<string, mixed>  $facts
     * @param  array<string, mixed>  $forecast
     * @return array{0: ?string, 1: ?string, 2: ?string, 3: int, 4: int, 5: string} [accepted text or null, why-not note, model, input tokens, output tokens, credits charged]
     */
    private function askModel(string $tenantId, string $userId, string $id, array $facts, array $forecast): array
    {
        $model = $this->models->pick($tenantId);
        if ($model === null) {
            return [null, 'no_model', null, 0, 0, '0.0000'];
        }
        $mult = $model['credit_multiplier'] ?? null;
        try {
            $reservation = $this->credits->reserve($tenantId, $this->pricing->reserveAmount(self::EST_INPUT, self::MAX_OUTPUT, $mult), 'analyst:'.$id, $id)['reservation_id'];
        } catch (CreditException $e) {
            return [null, $e->errorCode === 'insufficient_credits' ? 'no_credits' : 'credits', null, 0, 0, '0.0000'];
        } catch (\Throwable $e) {
            self::report($e);

            return [null, 'credits', null, 0, 0, '0.0000'];
        }

        $answer = null;
        $note = 'engine';
        try {
            $answer = $this->engine->explain($model['provider_id'], $model['model_key'], AnalystText::messages(AnalystText::factSheet($facts, $forecast), (bool) $forecast['enough']), $tenantId, $userId);
        } catch (ProviderCallException $e) {
            $note = 'provider';
        } catch (\Throwable $e) {
            self::report($e);
        }
        if ($answer === null) {
            try {
                $this->credits->release($tenantId, $reservation, 'analyst-release:'.$id);
            } catch (\Throwable $e) {
                self::report($e);
            }

            return [null, $note, null, 0, 0, '0.0000'];
        }

        $in = max(0, (int) ($answer['input_tokens'] ?? self::EST_INPUT));
        $out = max(0, (int) ($answer['output_tokens'] ?? 0));
        $charged = '0.0000';
        try {
            $charged = $this->credits->settle($tenantId, $reservation, $this->pricing->cost($in, $out, $mult), 'analyst-settle:'.$id, [
                'source' => 'analyst', 'user_id' => $userId, 'request_id' => $id, 'provider_id' => $model['provider_id'], 'model_id' => $model['id'], 'input_tokens' => $in, 'output_tokens' => $out,
            ])['charged'];
        } catch (\Throwable $e) {
            self::report($e);
        }
        $text = trim($answer['text']);
        $v = NarrativeCheck::verify($text, $facts, $forecast);

        return $v === 'ok' ? [$text, null, (string) $answer['model'], $in, $out, $charged] : [null, 'rejected_'.$v, (string) $answer['model'], $in, $out, $charged];
    }

    private static function report(\Throwable $e): void
    {
        if (function_exists('report')) {
            report($e);
        }
    }
}
