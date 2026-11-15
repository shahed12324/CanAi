<?php

declare(strict_types=1);

namespace App\Memory;

final class MemoryText
{
    public static function error(string $result): ?string
    {
        return match ($result) {
            'ok' => null,
            'bad_input' => 'The text is not valid — it cannot be empty or over 500 characters, and a type must be chosen.',
            'sensitive' => 'Secret information such as passwords, PINs, OTPs, API keys, card or ID numbers cannot be remembered — remove them from the statement.',
            'duplicate' => 'This is already remembered.',
            'limit' => 'The memory limit is full (200 personal, 100 business) — delete some old ones and add the new one.',
            'not_found' => 'The memory was not found.',
            'forbidden' => 'You do not have permission to change business memories.',
            default => 'Could not be done.',
        };
    }

    public const SUGGEST_FAIL = [
        'not_found' => 'The chat was not found.',
        'nothing' => 'Nothing new worth remembering was found in this chat.',
        'no_model' => 'No usable AI model in your plan.',
        'no_credits' => 'Not enough credits.',
        'provider' => 'AI The provider did not respond — try shortly.',
        'unreadable' => 'AI-reply was not usable — try again.',
        'failed' => 'Could not suggest.',
    ];
}
