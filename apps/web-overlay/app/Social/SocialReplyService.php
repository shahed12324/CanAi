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
 * Drafts a reply to one comment with AI and stores it as a DRAFT only (a person must still approve it).
 * Money invariant: exactly one settle (the provider answered, even if unreadable) or one release (no answer); nothing is reserved when the comment is missing/locked or the notes are too long.
 */
final class SocialReplyService
{
    private const MAX_OUTPUT = 500;

    public function __construct(
        private readonly SocialCommentRepo $repo,
        private readonly ModelPicker $models,
        private readonly AssistEngine $engine,
        private readonly CreditLedger $credits,
        private readonly CreditPricing $pricing,
    ) {}

    /** @return array{status: string, reply: ?string} status: ok|needs_human|notes_long|not_found|locked|no_model|no_credits|provider|unreadable|failed */
    public function draft(string $tenantId, string $userId, string $commentId, string $notes, string $requestId): array
    {
        $fail = fn (string $s) => ['status' => $s, 'reply' => null];
        $notes = trim((string) preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/u', '', $notes));
        if (mb_strlen($notes) > 500) {
            return $fail('notes_long');
        }
        try {
            $c = $this->repo->find($commentId);
        } catch (\Throwable $e) {
            self::report($e);

            return $fail('failed');
        }
        if ($c === null) {
            return $fail('not_found');
        }
        if (! in_array($c['status'], ['new', 'drafted'], true)) {
            return $fail('locked');
        }
        $model = $this->models->pick($tenantId, null);
        if ($model === null) {
            return $fail('no_model');
        }
        $mult = $model['credit_multiplier'] ?? null;
        $maxOut = min(self::MAX_OUTPUT, max(300, (int) ($model['max_output'] ?? self::MAX_OUTPUT)));
        $messages = SocialReplyPrompt::messages($c['message'], $c['author'], $c['post_caption'], $notes);
        $est = TokenEstimator::estimate($messages[0]['content'].$messages[1]['content']);
        try {
            $reservation = $this->credits->reserve($tenantId, $this->pricing->reserveAmount($est, $maxOut, $mult), 'srep:'.$requestId, $requestId)['reservation_id'];
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
                $this->credits->release($tenantId, $reservation, 'srep-release:'.$requestId);
            } catch (\Throwable $e) {
                self::report($e);
            }

            return $fail($status);
        }
        $in = max(0, (int) ($answer['input_tokens'] ?? $est));
        $out = max(0, (int) ($answer['output_tokens'] ?? 0));
        try {
            $this->credits->settle($tenantId, $reservation, $this->pricing->cost($in, $out, $mult), 'srep-settle:'.$requestId, [
                'source' => 'assist', 'user_id' => $userId, 'request_id' => $requestId, 'provider_id' => $model['provider_id'], 'model_id' => $model['id'], 'input_tokens' => $in, 'output_tokens' => $out,
            ]);
        } catch (\Throwable $e) {
            self::report($e);     // the hold is returned by credit_release_stale()
        }
        $p = SocialReplyText::parse((string) $answer['text']);
        if ($p === null) {
            return $fail('unreadable');
        }
        if ($p['needs_human']) {
            return $fail('needs_human');
        }
        try {
            $saved = $this->repo->draft($tenantId, $userId, $commentId, $p['reply']);
        } catch (\Throwable $e) {
            self::report($e);

            return $fail('failed');
        }

        return $saved === 'ok' ? ['status' => 'ok', 'reply' => $p['reply']] : $fail($saved === 'locked' || $saved === 'not_found' ? 'locked' : 'failed');
    }

    private static function report(\Throwable $e): void
    {
        if (function_exists('report')) {
            report($e);
        }
    }
}
