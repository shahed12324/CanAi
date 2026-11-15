<?php

declare(strict_types=1);

namespace App\Assist;

final class AssistText
{
    public const RUN = [
        'bad_mode' => 'Pick the type of task.',
        'empty' => 'Paste the text.',
        'too_long' => 'The text is too long — try a smaller part (meetings up to 20,000, emails up to 8,000 characters).',
        'no_model' => 'No AI model is enabled now. Tell the admin.',
        'no_credits' => 'Credits ran out. Buy credits and try again.',
        'provider' => 'AI The service is not responding now. Try again shortly (credits were not deducted).',
        'unreadable' => 'AI-reply could not be read. Try again. Credits for this attempt were deducted.',
        'failed' => 'Could not be done. Try again.',
    ];

    public static function run(string $status): string
    {
        return self::RUN[$status] ?? self::RUN['failed'];
    }
}
