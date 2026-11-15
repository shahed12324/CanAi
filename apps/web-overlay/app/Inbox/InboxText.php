<?php

declare(strict_types=1);

namespace App\Inbox;

/** What the staff screens say for each result code the database functions return. */
final class InboxText
{
    public static function sendError(string $result): ?string
    {
        return match ($result) {
            'queued', 'sent' => null,
            'emergency' => 'The emergency stop is on — no message can be sent.',
            'window_closed' => '24-hour rule: 24 hours have passed since the customer’s last message; a reply cannot be sent on this channel now.',
            'channel_paused' => 'The channel is disabled.',
            'empty' => 'The message is blank.',
            'too_long' => 'The message is over 4000 characters.',
            'rate_limited' => 'Many messages in one minute — try shortly.',
            'agent_off' => 'AI is not in auto mode on this channel.',
            'agent_limit' => 'The AI reply limit per hour for this chat is exceeded.',
            'human_mode' => 'The chat is with a human now.',
            default => 'The message could not be sent.',
        };
    }

    public static function kindBn(string $kind): string
    {
        return ['web_widget' => 'Website chat', 'messenger' => 'Messenger', 'instagram' => 'Instagram', 'whatsapp' => 'WhatsApp', 'telegram' => 'Telegram'][$kind] ?? $kind;
    }

    /** Parses one origin per line / comma; returns [list, null] or [[], error]. @return array{0: list<string>, 1: ?string} */
    public static function origins(string $raw): array
    {
        $out = [];
        foreach (preg_split('/[\s,]+/', trim($raw), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $o) {
            $o = strtolower(rtrim($o, '/'));
            if (preg_match('#\A(https://[a-z0-9]([a-z0-9.-]{0,120}[a-z0-9])?(:[0-9]{1,5})?|http://localhost(:[0-9]{1,5})?)\z#', $o) !== 1) {
                return [[], "\"$o\" Not valid — give it in the form https://yourshop.com (without a path)."];
            }
            $out[$o] = true;
        }
        if (count($out) > 10) {
            return [[], 'At most 10 addresses.'];
        }

        return [array_keys($out), null];
    }
}
