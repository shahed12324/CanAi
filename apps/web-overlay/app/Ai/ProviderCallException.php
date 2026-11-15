<?php

declare(strict_types=1);

namespace App\Ai;

/** Failure of a provider call. The message is already sanitised by the engine and never holds a key. */
final class ProviderCallException extends \RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message, public readonly ?int $httpStatus = null)
    {
        parent::__construct($message);
    }
}
