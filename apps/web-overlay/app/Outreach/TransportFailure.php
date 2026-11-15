<?php

declare(strict_types=1);

namespace App\Outreach;

final class TransportFailure extends \RuntimeException
{
    /** @param bool $permanent true for "this address/account will never work" (bad login, 5xx), false for "try again later" */
    public function __construct(public readonly string $code_, public readonly bool $permanent)
    {
        parent::__construct($code_);
    }
}
