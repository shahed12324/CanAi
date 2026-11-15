<?php

declare(strict_types=1);

namespace App\Messaging;

interface ChatBridge
{
    /**
     * Ask the AI on behalf of a linked user and return the whole answer.
     *
     * @param  list<array{filename: string, mime: string, size: int, text: string}>  $attachments
     * @return array{conversation_id: ?string, text: string, error: ?string, credits: ?string}  error is a user-facing message
     */
    public function ask(string $tenantId, string $userId, ?string $conversationId, string $text, array $attachments): array;
}
