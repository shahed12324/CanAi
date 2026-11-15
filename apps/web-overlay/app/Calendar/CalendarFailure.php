<?php

declare(strict_types=1);

namespace App\Calendar;

/** Internal: a step failed with a status the person can be told (revoked, unavailable, not_configured). */
final class CalendarFailure extends \RuntimeException
{
    public function __construct(public readonly string $status)
    {
        parent::__construct($status);
    }
}
