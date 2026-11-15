<?php

declare(strict_types=1);

namespace App\Chat;

use App\Ai\ProviderCallException;
use App\Credits\CreditException;
use App\Credits\CreditLedger;
use App\Knowledge\KnowledgeContext;
use App\Knowledge\KnowledgePrompt;
use App\Memory\MemoryContext;

/**
 * One chat turn: pick model -> save messages -> RESERVE credits -> stream -> SETTLE actual cost (or RELEASE).
 *
 * Money invariant: every reservation made here ends in exactly one settle or release, on every path
 * (success, provider error, client stop, crash of this method). If even that fails the reservation simply
 * expires and credit_release_stale() returns the credits - the customer is never charged for nothing.
 *
 * It is a generator of client events so it can be driven by an SSE controller or by a test:
 *   meta{conversation_id,message_id,model} -> delta{text}* -> done{...} | error{code,message,partial}
 */
final class ChatService
{
    public const FALLBACK_TITLE = 'New chat';

    public function __construct(
        private readonly ModelPicker $models,
        private readonly ChatStore $store,
        private readonly ChatEngine $engine,
        private readonly CreditLedger $credits,
        private readonly CreditPricing $pricing,
        private readonly int $maxOutputCap = 2048,
        private readonly int $maxInputCap = 24000,
        private readonly ?MemoryContext $memory = null,
        private readonly ?KnowledgeContext $knowledge = null,
        private readonly ?\App\Character\CharacterRepo $characters = null,
        private readonly ?\App\Companion\ModeRepo $modes = null,
    ) {}

    /**
     * @param  (callable(): bool)|null  $shouldStop  polled between chunks; true = the user pressed Stop / disconnected
     * @return \Generator<int, array<string, mixed>>
     */
    public function send(ChatRequest $r, ?callable $shouldStop = null): \Generator
    {
        $model = $this->models->pick($r->tenantId, $r->modelId);
        if ($model === null) {
            yield self::error('no_model', 'No usable AI model is enabled in your plan.');

            return;
        }

        $conversationId = $r->conversationId;
        $storedTitle = null;
        if ($conversationId !== null) {
            $conv = $this->store->conversation($r->tenantId, $r->userId, $conversationId);
            if ($conv === null) {
                yield self::error('not_found', 'The chat was not found.');

                return;
            }
        }
        $regenerate = $r->text === null;
        if ($regenerate && $conversationId === null) {
            yield self::error('nothing_to_regenerate', 'There is nothing to regenerate.');

            return;
        }
        if (! $regenerate && trim($r->text) === '' && $r->attachments === []) {
            yield self::error('empty_message', 'The message is empty.');

            return;
        }

        // ---- context (history from the store + the new turn) ----
        $history = $conversationId === null ? [] : $this->store->history($r->tenantId, $conversationId);
        $replaceId = null;
        if ($regenerate) {
            $last = $history === [] ? null : $history[count($history) - 1];
            if ($last !== null && $last['role'] === 'assistant') {
                $replaceId = $last['id'];
                array_pop($history);
            }
            if ($history === [] || $history[count($history) - 1]['role'] !== 'user') {
                yield self::error('nothing_to_regenerate', 'There is nothing to regenerate.');

                return;
            }
        } else {
            $history[] = ['id' => '', 'role' => 'user', 'content' => $r->text, 'attachments' => array_map(fn ($a) => ['filename' => $a['filename'], 'text' => $a['text']], $r->attachments)];
        }

        $maxOutput = max(16, min($model['max_output'] ?? $this->maxOutputCap, $this->maxOutputCap));
        $inputBudget = max(500, min($this->maxInputCap, ($model['context_window'] ?? 8000) - $maxOutput - 200));
        // An existing conversation keeps the mode it was created with; the request's mode only matters for a new one.
        $mode = isset($conv) ? (string) $conv['mode'] : (in_array($r->mode, ContextBuilder::MODES, true) ? $r->mode : 'general');
        $system = ContextBuilder::systemPrompt($mode);
        // A character conversation (fixed at creation) speaks in that character's persona. A deleted/unknown character falls back to the plain assistant.
        $characterId = isset($conv) ? $conv['character_id'] : $r->characterId;
        // Companion / Agent mode: its honesty rules go into the prompt, and a new chat with no character picked starts with the companion's own character.
        $modeInfo = ['mode' => 'agent', 'character_id' => null];
        if ($this->modes !== null) {
            try {
                $modeInfo = $this->modes->get($r->tenantId, $r->userId);
            } catch (\Throwable $e) {
                self::report($e);     // the mode is a bonus: a failure here must never stop the chat
            }
        }
        if ($characterId === null && ! isset($conv) && $modeInfo['mode'] === 'companion' && $modeInfo['character_id'] !== null && $this->characters?->find($r->tenantId, $modeInfo['character_id']) !== null) {
            $characterId = $modeInfo['character_id'];
        }
        $character = null;
        if ($characterId !== null && $this->characters !== null) {
            $character = $this->characters->find($r->tenantId, $characterId);
            if ($character !== null) {
                $system = \App\Character\PersonaPrompt::build($character);
            } elseif (! isset($conv)) {
                yield self::error('character_not_found', 'The character was not found.');

                return;
            }
        }
        $style = ReplyStyle::block($r->persona, $r->source, $character !== null);
        if ($style !== null) {
            $system .= "\n\n".$style;
        }
        if ($this->modes !== null) {
            $system .= "\n\n".\App\Companion\ModeRules::block($modeInfo['mode']);
        }
        $memoryUsed = 0;
        if ($this->memory !== null) {
            try {
                $m = $this->memory->block($r->tenantId, $r->userId);
                if ($m['text'] !== null) {
                    $system .= "\n\n".$m['text'];
                    $memoryUsed = $m['count'];
                }
            } catch (\Throwable $e) {
                self::report($e);     // memory is a bonus: a failure here must never stop the chat
            }
        }
        $sources = [];
        if ($this->knowledge !== null && $r->useKnowledge) {
            try {
                $k = $this->knowledge->retrieve($r->tenantId, $r->userId, $regenerate ? (string) $history[count($history) - 1]['content'] : (string) $r->text);
                if ($k['text'] !== null) {
                    $system .= "\n\n".$k['text'];
                    $sources = $k['sources'];
                }
            } catch (\Throwable $e) {
                self::report($e);     // the documents are a bonus: a failure here must never stop the chat
            }
        }
        $ctx = ContextBuilder::build($history, $inputBudget, $system);
        $multiplier = $model['credit_multiplier'] ?? null;
        $hold = $this->pricing->reserveAmount($ctx['input_tokens'], $maxOutput, $multiplier);

        // ---- persist: conversation, user message, assistant placeholder ----
        if ($conversationId === null) {
            $storedTitle = self::titleFrom((string) $r->text);
            $conversationId = $this->store->createConversation($r->tenantId, $r->userId, $storedTitle, $r->modelId, $mode, $character === null ? null : $character['id']);
        }
        if (! $regenerate) {
            $this->store->addUserMessage($r->tenantId, $r->userId, $conversationId, (string) $r->text, $r->attachments);
        }
        $assistantId = $this->store->startAssistant($r->tenantId, $conversationId, $model['id'], $model['provider_id']);
        yield ['type' => 'meta', 'conversation_id' => $conversationId, 'message_id' => $assistantId, 'model' => $model['name'], 'title' => $storedTitle, 'truncated' => $ctx['truncated'], 'memory' => $memoryUsed, 'knowledge' => count($sources), 'mode' => $mode, 'character' => $character === null ? null : ['id' => $character['id'], 'name' => $character['name'], 'avatar_style' => $character['avatar_style'], 'voice' => $character['voice']]];

        // ---- reserve ----
        try {
            $reservation = $this->credits->reserve($r->tenantId, $hold, 'chat:'.$assistantId, $assistantId)['reservation_id'];
        } catch (CreditException $e) {
            $this->store->finish($assistantId, 'error', '', $e->errorCode, null, null, '0.0000', null);
            yield self::error($e->errorCode, $e->userMessage(), $assistantId);

            return;
        } catch (\Throwable $e) {
            self::report($e);
            $this->store->finish($assistantId, 'error', '', 'internal', null, null, '0.0000', null);
            yield self::error('internal', 'A temporary problem with the credit system. Try again shortly.', $assistantId);

            return;
        }

        // ---- stream ----
        $text = '';
        $inTok = null;
        $outTok = null;
        $failure = null;
        $failureMessage = null;
        $stopped = false;
        $done = false;
        $lastSave = microtime(true);
        try {
            try {
                foreach ($this->engine->stream($model['provider_id'], $model['model_key'], $ctx['messages'], $r->tenantId, $r->userId, $maxOutput) as $ev) {
                    $inTok = $ev['input_tokens'] ?? $inTok;
                    $outTok = $ev['output_tokens'] ?? $outTok;
                    if ($ev['text'] !== '') {
                        $text .= $ev['text'];
                        yield ['type' => 'delta', 'text' => $ev['text']];
                    }
                    if (microtime(true) - $lastSave > 2.0) {
                        $this->store->progress($assistantId, $text);
                        $lastSave = microtime(true);
                    }
                    if ($shouldStop !== null && $shouldStop()) {
                        $stopped = true;
                        break;
                    }
                }
            } catch (ProviderCallException $e) {
                $failure = $e->errorCode;
                $failureMessage = $e->getMessage();
            } catch (\Throwable $e) {
                self::report($e);
                $failure = 'engine_error';
                $failureMessage = 'AI Could not connect to the service.';
            }

            // ---- settle or release ----
            if ($failure !== null && $text === '') {
                $this->releaseQuietly($r->tenantId, $reservation, $assistantId);
                $this->store->finish($assistantId, 'error', '', $failure, null, null, '0.0000', null);
                $done = true;
                yield self::error($failure, self::friendly($failure, (string) $failureMessage), $assistantId);

                return;
            }

            $in = $inTok ?? $ctx['input_tokens'];
            $out = $outTok ?? TokenEstimator::estimate($text);
            $cost = $this->pricing->cost($in, $out, $multiplier);
            $charged = '0.0000';
            try {
                $res = $this->credits->settle($r->tenantId, $reservation, $cost, 'chat-settle:'.$assistantId, [
                    'source' => 'chat', 'user_id' => $r->userId, 'request_id' => $assistantId,
                    'provider_id' => $model['provider_id'], 'model_id' => $model['id'],
                    'input_tokens' => $in, 'output_tokens' => $out,
                ]);
                $charged = $res['charged'];
            } catch (\Throwable $e) {
                self::report($e);   // reservation stays held and is returned by credit_release_stale(): customer is not charged
            }
            $status = ($failure !== null || $stopped) ? 'partial' : 'complete';
            $this->store->finish($assistantId, $status, $text, $failure ?? ($stopped ? 'stopped' : null), $in, $out, $charged, $reservation);
            $this->store->touch($conversationId);
            if ($replaceId !== null) {
                $this->store->hide($replaceId);
            }
            $done = true;

            if ($failure !== null) {
                yield self::error($failure, self::friendly($failure, (string) $failureMessage), $assistantId, true, $charged);

                return;
            }
            yield ['type' => 'done', 'message_id' => $assistantId, 'credits' => $charged, 'input_tokens' => $in, 'output_tokens' => $out, 'stopped' => $stopped, 'sources' => $this->recordSources($r, $assistantId, $sources, $text)];
        } finally {
            if (! $done) {
                // The consumer abandoned the generator or something threw: never leave the reservation or the row dangling.
                $this->releaseQuietly($r->tenantId, $reservation, $assistantId);
                try {
                    $this->store->finish($assistantId, $text === '' ? 'error' : 'partial', $text, 'interrupted', null, null, '0.0000', null);
                } catch (\Throwable $e) {
                    self::report($e);
                }
            }
        }
    }

    /**
     * @param  list<array{n: int, document_id: string, title: string, seq: int, excerpt: string, cited: bool}>  $sources
     * @return list<array{n: int, document_id: string, title: string, seq: int, excerpt: string, cited: bool}>
     */
    private function recordSources(ChatRequest $r, string $assistantId, array $sources, string $answer): array
    {
        if ($sources === [] || $this->knowledge === null) {
            return [];
        }
        $sources = KnowledgePrompt::markCited($sources, $answer);
        try {
            $this->knowledge->record($r->tenantId, $r->userId, $assistantId, $sources);
        } catch (\Throwable $e) {
            self::report($e);   // the answer is already saved and billed; only the source list is lost
        }

        return $sources;
    }

    private function releaseQuietly(string $tenantId, string $reservation, string $assistantId): void
    {
        try {
            $this->credits->release($tenantId, $reservation, 'chat-release:'.$assistantId);
        } catch (\Throwable $e) {
            self::report($e);   // stale-sweep will return the credits
        }
    }

    public static function titleFrom(string $text): string
    {
        $t = trim((string) preg_replace('/\s+/u', ' ', $text));

        return $t === '' ? self::FALLBACK_TITLE : mb_substr($t, 0, 60);
    }

    /** @return array<string, mixed> */
    private static function error(string $code, string $message, ?string $messageId = null, bool $partial = false, ?string $credits = null): array
    {
        return ['type' => 'error', 'code' => $code, 'message' => $message, 'message_id' => $messageId, 'partial' => $partial, 'credits' => $credits];
    }

    private static function friendly(string $code, string $raw): string
    {
        return match ($code) {
            'rate_limited' => 'AI The provider is busy now. Try again shortly.',
            'timeout' => 'The reply is taking too long. Try again.',
            'auth_failed', 'no_credential', 'provider_disabled', 'not_found' => 'This model cannot be used now. Pick another model.',
            'engine_error' => 'AI Could not connect to the service.',
            default => 'The reply could not be generated. Try again.',
        };
    }

    private static function report(\Throwable $e): void
    {
        if (function_exists('report')) {
            report($e);
        }
    }
}
