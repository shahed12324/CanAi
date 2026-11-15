<?php

declare(strict_types=1);

namespace App\Developer;

final class DeveloperText
{
    public const MESSAGES = [
        'bad_name' => 'Give the key a name (max 80 characters).',
        'bad_scopes' => 'Pick at least one permission.',
        'bad_days' => 'Validity must be within 1–365 days (empty = no expiry).',
        'limit' => 'A person can have at most 10 active keys. Revoke some old ones.',
        'failed' => 'Could not be done. Try again.',
        'bad_url' => 'The address is not valid: it must start with https://, be a real domain (not localhost or an internal network), port 443 or 8443.',
        'bad_input' => 'Pick at least one event.',
        'not_found' => 'Not found.',
    ];

    public static function message(string $code): string
    {
        return self::MESSAGES[$code] ?? 'Could not be done.';
    }
}
