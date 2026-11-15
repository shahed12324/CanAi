<?php

declare(strict_types=1);

namespace App\Inbox\Agent;

use App\Ai\ProviderCallException;
use App\Chat\ChatEngine;
use App\Chat\CreditPricing;
use App\Chat\ModelPicker;
use App\Chat\TokenEstimator;
use App\Credits\CreditException;
use App\Credits\CreditLedger;
use App\Inbox\InboxRepo;

/**
 * Answers one customer message. Nothing here can lose money or say something the shop did not publish:
 *  - words like "order", "complaint", "human" never reach the model (Triggers),
 *  - the model sees only published products + the owner's business info, the customer's text is quoted data,
 *  - the reply is checked by code (ReplyGuard) before it is sent or saved,
 *  - credits are reserved first, settled with the real cost, and released on every failure,
 *  - the database refuses agent messages unless the channel is in auto mode, and caps 12 per hour per conversation.
 * Any doubt -> hand the chat to a person with a reason. Returns the outcome word (used by tests and the log).
 */
final class SupportAgent
{
    public const HANDOFF_NOTICE = 'One of our representatives will look at your question. Please wait a little; you will get a reply soon. 🙏';

    public function __construct(
        private readonly InboxRepo $inbox,
        private readonly AgentRepo $agent,
        private readonly ModelPicker $models,
        private readonly ChatEngine $engine,
        private readonly CreditLedger $credits,
        private readonly CreditPricing $pricing,
        private readonly int $maxOutput = 400,
        private readonly ?\App\Inbox\Agent\Orders\OrderFlow $orders = null,
    ) {}

    /** @return string skipped|handoff:<reason>|drafted|sent */
    public function run(string $tenantId, string $conversationId, int $seq, string $shopName): string
    {
        $conv = $this->inbox->conversation($conversationId);
        if ($conv === null || ($conv['agent_mode'] ?? 'off') === 'off' || $conv['mode'] !== 'bot' || $conv['status'] !== 'open') {
            return 'skipped';
        }
        $auto = $conv['agent_mode'] === 'auto';
        $msgs = $this->inbox->messages($conversationId, max(0, $seq - 12), 40);
        $trigger = null;
        foreach ($msgs as $m) {
            if ((int) $m['seq'] > $seq && $m['direction'] === 'in') {
                return 'skipped';                                   // a newer customer message has its own run
            }
            if ((int) $m['seq'] > $seq && $m['direction'] === 'out') {
                return 'skipped';                                   // someone already answered
            }
            if ((int) $m['seq'] === $seq && $m['direction'] === 'in') {
                $trigger = (string) $m['body'];
            }
        }
        if ($trigger === null) {
            return 'skipped';
        }
        $why = Triggers::needsHuman($trigger);
        // Taking orders in chat: only on an auto channel and only if the owner switched it on. A complaint / "human please" always wins.
        $flow = $this->orders !== null && $auto && $this->orders->enabled();
        if ($flow && ($why === 'order_intent' || ($why === null && $this->orders->active($conversationId)))) {
            $history = [];
            foreach ($msgs as $m) {
                if ((int) $m['seq'] <= $seq && in_array($m['direction'], ['in', 'out'], true) && trim((string) $m['body']) !== '') {
                    $history[] = ['from' => $m['direction'] === 'in' ? 'customer' : 'us', 'text' => (string) $m['body']];
                }
            }
            $r = $this->orders->run($tenantId, $conversationId, $trigger, $history);

            return str_starts_with($r, 'handoff:') ? $this->handoff($conversationId, $auto, substr($r, 8)) : $r;
        }
        if ($why !== null) {
            if ($flow) {
                $this->orders->discard($conversationId);
            }

            return $this->handoff($conversationId, $auto, $why);
        }

        $settings = $this->agent->settings();
        $found = $this->agent->search(Knowledge::keywords($trigger));
        $know = Knowledge::render($found['products']);
        $history = [];
        foreach ($msgs as $m) {
            if ((int) $m['seq'] <= $seq && in_array($m['direction'], ['in', 'out'], true) && trim((string) $m['body']) !== '') {
                $history[] = ['from' => $m['direction'] === 'in' ? 'customer' : 'us', 'text' => (string) $m['body']];
            }
        }
        $prompt = AgentPrompt::build($shopName, $settings, $know, $found['matched'], $found['categories'], $history);

        $model = $this->models->pick($tenantId, null);
        if ($model === null) {
            return $this->handoff($conversationId, $auto, 'no_model');
        }
        $inEst = TokenEstimator::estimate($prompt[0]['content'].$prompt[1]['content']);
        $max = max(64, min($model['max_output'] ?? $this->maxOutput, $this->maxOutput));
        $mult = $model['credit_multiplier'] ?? null;
        $id = bin2hex(random_bytes(13));
        try {
            $reservation = $this->credits->reserve($tenantId, $this->pricing->reserveAmount($inEst, $max, $mult), 'inbox-ai:'.$id, $id)['reservation_id'];
        } catch (CreditException) {
            return $this->handoff($conversationId, $auto, 'credits');
        } catch (\Throwable $e) {
            self::report($e);

            return $this->handoff($conversationId, $auto, 'unsure');
        }

        $text = '';
        $inTok = null;
        $outTok = null;
        $settled = false;
        try {
            try {
                foreach ($this->engine->stream($model['provider_id'], $model['model_key'], $prompt, $tenantId, null, $max) as $ev) {
                    $text .= $ev['text'];
                    $inTok = $ev['input_tokens'] ?? $inTok;
                    $outTok = $ev['output_tokens'] ?? $outTok;
                }
            } catch (ProviderCallException) {
                return $this->handoff($conversationId, $auto, 'unsure');
            } catch (\Throwable $e) {
                self::report($e);

                return $this->handoff($conversationId, $auto, 'unsure');
            }
            $out = AgentPrompt::parse($text);
            if ($out === null) {
                return $this->handoff($conversationId, $auto, 'unsure');   // unusable output is not charged (hold released below)
            }
            try {
                $this->credits->settle($tenantId, $reservation, $this->pricing->cost($inTok ?? $inEst, $outTok ?? TokenEstimator::estimate($text), $mult), 'inbox-ai-settle:'.$id, [
                    'source' => 'inbox', 'user_id' => null, 'request_id' => $id, 'provider_id' => $model['provider_id'], 'model_id' => $model['id'],
                    'input_tokens' => $inTok ?? $inEst, 'output_tokens' => $outTok ?? TokenEstimator::estimate($text),
                ]);
            } catch (\Throwable $e) {
                self::report($e);   // the hold expires and returns by itself
            }
            $settled = true;
        } finally {
            if (! $settled) {
                try {
                    $this->credits->release($tenantId, $reservation, 'inbox-ai-release:'.$id);
                } catch (\Throwable $e) {
                    self::report($e);
                }
            }
        }

        if ($out['handoff'] || $out['reply'] === '') {
            return $this->handoff($conversationId, $auto, 'unsure');
        }
        $ownerText = $settings['business_info']."\n".$settings['instructions'];
        if (ReplyGuard::check($out['reply'], $know['prices'], $ownerText) !== null) {
            return $this->handoff($conversationId, $auto, 'guard');
        }
        if (! $auto) {
            $this->agent->saveDraft($tenantId, $conversationId, $out['reply'], $seq);

            return 'drafted';
        }
        $r = $this->inbox->send($conversationId, null, 'agent', $out['reply']);
        if (in_array($r['result'], ['queued', 'sent'], true)) {
            return 'sent';
        }

        return $this->handoff($conversationId, $auto, $r['result'] === 'agent_limit' ? 'limit' : 'unsure', $r['result'] !== 'human_mode');
    }

    private function handoff(string $conversationId, bool $auto, string $reason, bool $notify = true): string
    {
        if ($auto && $notify) {
            $this->inbox->send($conversationId, null, 'agent', self::HANDOFF_NOTICE);   // before the mode flips: agent sends need bot mode
        }
        $this->inbox->setMode($conversationId, null, 'human', $reason);

        return 'handoff:'.$reason;
    }

    private static function report(\Throwable $e): void
    {
        if (function_exists('report')) {
            report($e);
        }
    }
}
