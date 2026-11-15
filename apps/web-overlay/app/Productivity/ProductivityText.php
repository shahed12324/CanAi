<?php

declare(strict_types=1);

namespace App\Productivity;

/** Plain Bengali messages for every answer the database or the form check can give. */
final class ProductivityText
{
    public const MESSAGES = [
        'empty' => 'Write a title.',
        'too_long' => 'The title can be at most 200 and the description at most 2000 characters.',
        'bad_priority' => 'Pick the importance.',
        'bad_date' => 'The date is not valid.',
        'bad_time' => 'The reminder time is not valid.',
        'bad_input' => 'The details given are not valid — check that there is a title and the length.',
        'limit' => 'The limit is full (max 500 ongoing tasks, max 1000 notes). Delete or finish old ones and try again.',
        'not_found' => 'This is no longer found.',
    ];

    public static function error(string $result): ?string
    {
        return $result === 'ok' ? null : (self::MESSAGES[$result] ?? 'Could not be done.');
    }
}
