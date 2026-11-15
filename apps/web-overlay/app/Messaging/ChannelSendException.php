<?php

declare(strict_types=1);

namespace App\Messaging;

/** Raised for Telegram API failures. The message never contains the bot token. */
final class ChannelSendException extends \RuntimeException {}
