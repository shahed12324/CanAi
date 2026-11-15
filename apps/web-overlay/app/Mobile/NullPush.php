<?php

declare(strict_types=1);

namespace App\Mobile;

/** Used when push is switched off (no network call). Notifications still show inside the app. */
final class NullPush implements PushSender
{
    public function send(array $messages): array
    {
        return array_map(fn () => 'ok', $messages);
    }
}
