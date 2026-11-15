<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Messaging\ChatBridge;

final class FakeBridge implements ChatBridge
{
    public array $calls = [];

    public array $reply = ['conversation_id' => 'CONV1', 'text' => 'Replies', 'error' => null, 'credits' => '3'];

    public function ask(string $tenantId, string $userId, ?string $conversationId, string $text, array $attachments): array
    {
        $this->calls[] = compact('tenantId', 'userId', 'conversationId', 'text', 'attachments');

        return $this->reply;
    }
}
