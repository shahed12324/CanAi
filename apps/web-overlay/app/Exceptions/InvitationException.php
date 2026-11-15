<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

final class InvitationException extends RuntimeException
{
    /** Machine codes raised by the invitation_accept() SQL function. */
    public const CODES = [
        'invitation_invalid',
        'invitation_used',
        'invitation_expired',
        'invitation_email_mismatch',
        'tenant_unavailable',
        'user_unavailable',
        'user_email_unverified',
        'seat_limit',
        'license_expired',
    ];

    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }
}
