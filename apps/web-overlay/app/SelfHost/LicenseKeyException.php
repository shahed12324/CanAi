<?php

declare(strict_types=1);

namespace App\SelfHost;

final class LicenseKeyException extends \RuntimeException
{
    public const CODES = ['malformed', 'bad_signature', 'bad_payload', 'expired', 'not_yet_valid', 'no_public_key'];

    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }
}
