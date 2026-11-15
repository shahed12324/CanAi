<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Mobile\PushSender;

final class FakePushSender implements PushSender
{
    public array $sent = [];

    /** @var list<string> */
    public array $answers = [];

    public function send(array $messages): array
    {
        $this->sent = array_merge($this->sent, $messages);

        return array_map(fn ($m, $i) => $this->answers[$i] ?? 'ok', $messages, array_keys($messages));
    }
}
