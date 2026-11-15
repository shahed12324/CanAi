<?php

declare(strict_types=1);

namespace App\Memory;

use App\Ai\ProviderCallException;
use App\Chat\ChatStore;
use App\Chat\CreditPricing;
use App\Chat\ModelPicker;
use App\Credits\CreditException;
use App\Credits\CreditLedger;

/**
 * "Suggest what to remember from this chat". The AI only PROPOSES: the proposals are stored as pending suggestions and become memories only when the person accepts them.
 * The person's own conversation is the only input; it is read through ChatStore, which checks ownership.
 *
 * Money invariant (same as chat / bill scan / analyst): every reservation ends in exactly one settle or release.
 */
final class MemorySuggestService
{
    private const EST_INPUT = 2500;

    private const MAX_OUTPUT = 500;

    private const TURNS = 24;

    public function __construct(
        private readonly ChatStore $chats,
        private readonly ModelPicker $models,
        private readonly MemoryEngine $engine,
        private readonly MemoryRepo $repo,
        private readonly CreditLedger $credits,
        private readonly CreditPricing $pricing,
    ) {}

    /**
     * @param  list<string>  $ids  fresh ids for up to five suggestions
     * @return array{status: string, count: int} status: ok | nothing | not_found | no_model | no_credits | provider | unreadable | failed
     */
    public function suggest(string $tenantId, string $userId, string $conversationId, array $ids, string $requestId): array
    {
        if ($this->chats->conversation($tenantId, $userId, $conversationId) === null) {
            return ['status' => 'not_found', 'count' => 0];
        }
        $turns = array_map(fn ($m) => ['role' => (string) $m['role'], 'content' => (string) $m['content']],
            array_values(array_filter($this->chats->history($tenantId, $conversationId), fn ($m) => in_array($m['role'], ['user', 'assistant'], true) && trim((string) $m['content']) !== '')));
        $turns = array_slice($turns, -self::TURNS);
        if (! in_array('user', array_column($turns, 'role'), true)) {
            return ['status' => 'nothing', 'count' => 0];
        }
        $model = $this->models->pick($tenantId, null);
        if ($model === null) {
            return ['status' => 'no_model', 'count' => 0];
        }
        $mult = $model['credit_multiplier'] ?? null;
        try {
            $reservation = $this->credits->reserve($tenantId, $this->pricing->reserveAmount(self::EST_INPUT, self::MAX_OUTPUT, $mult), 'memory:'.$requestId, $requestId)['reservation_id'];
        } catch (CreditException $e) {
            return ['status' => $e->errorCode === 'insufficient_credits' ? 'no_credits' : 'failed', 'count' => 0];
        } catch (\Throwable $e) {
            self::report($e);

            return ['status' => 'failed', 'count' => 0];
        }

        $answer = null;
        $status = 'failed';
        try {
            $answer = $this->engine->complete($model['provider_id'], $model['model_key'], MemoryPrompt::suggestMessages($turns), $tenantId, $userId);
        } catch (ProviderCallException $e) {
            $status = 'provider';
        } catch (\Throwable $e) {
            self::report($e);
        }
        if ($answer === null) {
            try {
                $this->credits->release($tenantId, $reservation, 'memory-release:'.$requestId);
            } catch (\Throwable $e) {
                self::report($e);
            }

            return ['status' => $status, 'count' => 0];
        }
        $in = max(0, (int) ($answer['input_tokens'] ?? self::EST_INPUT));
        $out = max(0, (int) ($answer['output_tokens'] ?? 0));
        try {
            $this->credits->settle($tenantId, $reservation, $this->pricing->cost($in, $out, $mult), 'memory-settle:'.$requestId, [
                'source' => 'memory', 'user_id' => $userId, 'request_id' => $requestId, 'provider_id' => $model['provider_id'], 'model_id' => $model['id'], 'input_tokens' => $in, 'output_tokens' => $out,
            ]);
        } catch (\Throwable $e) {
            self::report($e);     // the hold is returned by credit_release_stale()
        }

        $items = MemoryPrompt::parseSuggestions($answer['text']);
        if ($items === null) {
            return ['status' => 'unreadable', 'count' => 0];
        }
        $n = $items === [] ? 0 : $this->repo->saveSuggestions($tenantId, $userId, $conversationId, $items, $ids);

        return ['status' => $n > 0 ? 'ok' : 'nothing', 'count' => $n];
    }

    private static function report(\Throwable $e): void
    {
        if (function_exists('report')) {
            report($e);
        }
    }
}
