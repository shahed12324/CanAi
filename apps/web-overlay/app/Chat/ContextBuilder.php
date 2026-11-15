<?php

declare(strict_types=1);

namespace App\Chat;

/** Turns stored history into the message list sent to the model, within a token budget. Pure. */
final class ContextBuilder
{
    public const SYSTEM_PROMPT = 'You are CANAI, a helpful assistant. Reply in the language the user writes in. Be accurate; if you are unsure, say so.';

    public const MODES = ['general', 'code'];

    public const CODE_PROMPT = 'You are CANAI Coding Assistant, a careful senior software engineer. '
        .'Reply in the language the user writes in (for Bengali users: explain in Bengali, keep code, identifiers and error messages in English). '
        .'Put every code sample in a fenced block with its language tag. When fixing a bug, say what the cause is first, then show the smallest change that fixes it. '
        .'Ask for the missing file, error text or version instead of guessing. You cannot run code or see the user\'s files: never claim you ran or tested anything; say what to run to verify. '
        .'Never put real secrets, passwords or API keys in examples, warn when the user pastes one, and point out security problems (injection, unsafe deserialisation, missing authorisation) you notice. '
        .'Prefer the standard library and well-known packages; mention trade-offs briefly.';

    public static function systemPrompt(string $mode): string
    {
        return $mode === 'code' ? self::CODE_PROMPT : self::SYSTEM_PROMPT;
    }

    /**
     * @param  list<array{role: string, content: string, attachments?: list<array{filename: string, text: string}>}>  $history  oldest first, INCLUDING the new user turn as the last item
     * @return array{messages: list<array{role: string, content: string}>, input_tokens: int, truncated: bool}
     */
    public static function build(array $history, int $maxInputTokens, string $system = self::SYSTEM_PROMPT): array
    {
        $turns = array_map(fn (array $m) => ['role' => $m['role'], 'content' => self::withAttachments($m)], $history);
        $budget = $maxInputTokens - TokenEstimator::estimate($system);
        $kept = [];
        $used = 0;
        $truncated = false;

        for ($i = count($turns) - 1; $i >= 0; $i--) {
            $cost = TokenEstimator::estimate($turns[$i]['content']) + 4;   // per-message overhead
            if ($i === count($turns) - 1) {
                if ($cost > $budget) {   // the newest turn alone is too big: cut its head, keep the tail the user just wrote
                    $turns[$i]['content'] = self::tail($turns[$i]['content'], max(1, $budget - 4));
                    $cost = TokenEstimator::estimate($turns[$i]['content']) + 4;
                    $truncated = true;
                }
            } elseif ($used + $cost > $budget) {
                $truncated = true;
                break;
            }
            $kept[] = $turns[$i];
            $used += $cost;
        }
        $kept = array_reverse($kept);
        // Providers want the conversation to start with a user turn.
        while ($kept !== [] && $kept[0]['role'] !== 'user') {
            array_shift($kept);
            $truncated = true;
        }

        $messages = array_merge([['role' => 'system', 'content' => $system]], $kept);
        $tokens = TokenEstimator::estimate($system) + array_sum(array_map(fn ($m) => TokenEstimator::estimate($m['content']) + 4, $kept));

        return ['messages' => $messages, 'input_tokens' => $tokens, 'truncated' => $truncated];
    }

    /** @param array{content: string, attachments?: list<array{filename: string, text: string}>} $m */
    private static function withAttachments(array $m): string
    {
        $out = $m['content'];
        foreach ($m['attachments'] ?? [] as $a) {
            $out .= "\n\n[Attached file: {$a['filename']}]\n{$a['text']}\n[End of file]";
        }

        return $out;
    }

    /** Keeps roughly the last $tokens tokens of $text (by shrinking characters until the estimate fits). */
    private static function tail(string $text, int $tokens): string
    {
        $chars = max(1, (int) floor(mb_strlen($text) * min(1, $tokens / max(1, TokenEstimator::estimate($text)))));
        $cut = mb_substr($text, -$chars);
        while ($chars > 1 && TokenEstimator::estimate($cut) > $tokens) {
            $chars = (int) floor($chars * 0.9);
            $cut = mb_substr($text, -$chars);
        }

        return $cut;
    }
}
