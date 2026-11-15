<?php

declare(strict_types=1);

namespace App\Office;

/** Plain Bengali messages for every answer the creator can give. */
final class OfficeText
{
    public const CREATE = [
        'empty_request' => 'Write what you want to make.',
        'bad_kind' => 'Pick the file type.',
        'no_model' => 'No AI model is enabled now. Tell the admin.',
        'no_credits' => 'Credits ran out. Buy credits and try again.',
        'provider' => 'AI The service is not responding now. Try again shortly (credits were not deducted).',
        'unreadable' => 'AI-reply could not be turned into a file (it may have been too large). Shorten the request and try again. Credits for this attempt were deducted.',
        'limit' => 'Saved files have reached 100. Delete some old ones and try again.',
        'failed' => 'Could not be done. Try again.',
    ];

    public static function create(string $status): string
    {
        return self::CREATE[$status] ?? self::CREATE['failed'];
    }
}
