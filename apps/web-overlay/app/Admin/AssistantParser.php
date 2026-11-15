<?php

declare(strict_types=1);

namespace App\Admin;

/**
 * Turns an admin's plain sentence into one known intent. Deterministic on purpose: the assistant can only ever do
 * what is listed here, and every state-changing intent needs a confirm click (see AssistantController).
 *
 * @phpstan-type Intent array{name: string, args: array<string, mixed>}
 */
final class AssistantParser
{
    /** Intents that change data (shown with a Confirm button, never run straight from the sentence). */
    public const ACTIONS = ['suspend_shop', 'activate_shop', 'start_trial', 'extend_trial', 'announce'];

    /** @return array{name: string, args: array<string, mixed>} */
    public static function parse(string $text): array
    {
        $t = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
        $l = mb_strtolower($t);
        if ($l === '' || $l === 'help' || $l === '?') {
            return ['name' => 'help', 'args' => []];
        }

        if (preg_match('/^(?:suspend|deactivate|block)\s+(?:the\s+)?(?:shop|workspace)?\s*(\S+)(?:\s+(?:because|reason|for)\s+(.+))?$/iu', $t, $m)) {
            return ['name' => 'suspend_shop', 'args' => ['shop' => $m[1], 'reason' => $m[2] ?? '']];
        }
        if (preg_match('/^(?:activate|unsuspend|reactivate|unblock)\s+(?:the\s+)?(?:shop|workspace)?\s*(\S+)$/iu', $t, $m)) {
            return ['name' => 'activate_shop', 'args' => ['shop' => $m[1]]];
        }
        if (preg_match('/^extend\s+(?:the\s+)?trial\s+(?:of\s+|for\s+)?(\S+?)\s+(?:by\s+)?(\d{1,6})\s*(?:days?|d)?$/iu', $t, $m)) {
            return ['name' => 'extend_trial', 'args' => ['shop' => $m[1], 'days' => min(365, max(1, (int) $m[2]))]];
        }
        if (preg_match('/^(?:start|give|grant)\s+(?:a\s+)?(?:free\s+)?trial\s+(?:to\s+|for\s+)?(\S+?)(?:\s+(?:for\s+)?(\d{1,6})\s*(?:days?|d))?(?:\s+on\s+(\S+))?$/iu', $t, $m)) {
            return ['name' => 'start_trial', 'args' => ['shop' => $m[1], 'days' => isset($m[2]) && $m[2] !== '' ? min(365, max(1, (int) $m[2])) : 14, 'plan' => $m[3] ?? '']];
        }
        if (preg_match('/^(?:announce|broadcast|notify all shops)\s*[:\-]?\s*(.{3,160})$/iu', $t, $m)) {
            return ['name' => 'announce', 'args' => ['title' => $m[1]]];
        }
        if (preg_match('/^(?:find|show|search|lookup|look up)\s+(?:the\s+)?(?:shop|workspace)\s+(.+)$/iu', $t, $m)) {
            return ['name' => 'find_shop', 'args' => ['term' => trim($m[1])]];
        }

        if (preg_match('/\b(revenue|income|earning|sales|paid orders)\b/u', $l)) {
            return ['name' => 'revenue', 'args' => []];
        }
        if (preg_match('/\b(tickets?|support)\b/u', $l)) {
            return ['name' => 'tickets', 'args' => []];
        }
        if (preg_match('/\btrials?\b/u', $l)) {
            return ['name' => 'trials', 'args' => []];
        }
        if (preg_match('/\b(usage|top shops|spend|spending|credits used)\b/u', $l)) {
            return ['name' => 'usage', 'args' => []];
        }
        if (preg_match('/\b(stats?|summary|overview|how many|total shops|shops)\b/u', $l)) {
            return ['name' => 'stats', 'args' => []];
        }

        return ['name' => 'unknown', 'args' => ['text' => mb_substr($t, 0, 200)]];
    }

    public static function isAction(string $intent): bool
    {
        return in_array($intent, self::ACTIONS, true);
    }
}
