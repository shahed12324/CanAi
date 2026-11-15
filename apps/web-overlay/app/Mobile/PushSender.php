<?php

declare(strict_types=1);

namespace App\Mobile;

interface PushSender
{
    /**
     * @param  list<array{to: string, title: string, body: string, data: array<string, mixed>}>  $messages
     * @return list<string> one result per message, same order: 'ok' | 'invalid_token' | 'error:<code>'
     */
    public function send(array $messages): array;
}
