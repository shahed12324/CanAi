<?php

declare(strict_types=1);

namespace App\Inbox\Channels;

/** $permanent = true: retrying cannot help (bad token, blocked user, rejected content). */
final class ChannelException extends \RuntimeException
{
    public function __construct(string $message, public readonly bool $permanent = false)
    {
        parent::__construct($message);
    }
}
