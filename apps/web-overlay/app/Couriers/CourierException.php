<?php

declare(strict_types=1);

namespace App\Couriers;

/**
 * $uncertain = true: the request may have reached the courier (timeout, 5xx) so we do NOT know whether a parcel was created.
 * Booking then stays "in progress" for a person to check on the courier's own panel instead of being retried blindly.
 */
final class CourierException extends \RuntimeException
{
    public function __construct(string $message, public readonly bool $uncertain = false)
    {
        parent::__construct($message);
    }
}
