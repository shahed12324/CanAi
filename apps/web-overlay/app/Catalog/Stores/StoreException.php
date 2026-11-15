<?php

declare(strict_types=1);

namespace App\Catalog\Stores;

/** codes: auth_failed | not_found | rate_limited | bad_response | network | invalid */
final class StoreException extends \RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message = '')
    {
        parent::__construct($message !== '' ? $message : $errorCode);
    }

    public function userMessage(): string
    {
        return match ($this->errorCode) {
            'auth_failed' => 'The store did not accept our key/token — connect again with new ones.',
            'not_found' => 'The store’s API was not found — check that the address and plugin/app are correct.',
            'rate_limited' => 'The store is getting many requests — it will run again shortly.',
            'invalid' => $this->getMessage(),
            default => 'The store could not be reached or the reply could not be understood.',
        };
    }
}
