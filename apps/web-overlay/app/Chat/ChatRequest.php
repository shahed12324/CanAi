<?php

declare(strict_types=1);

namespace App\Chat;

final class ChatRequest
{
    /**
     * @param  ?string  $conversationId  null = start a new conversation
     * @param  ?string  $text  null = regenerate: answer the conversation's last user message again
     * @param  list<array{filename: string, mime: string, size: int, text: string}>  $attachments
     * @param  ?string  $modelId  explicit model picked by the user for this conversation (must be allowed)
     * @param  bool  $useKnowledge  false = do not consult the company Knowledge Base for this message
     * @param  ?string  $characterId  talk to this character (new conversations only; an existing conversation keeps its own)
     * @param  string  $mode  'general' | 'code' — only used when the conversation is new; an existing conversation keeps its own mode
     * @param  ?string  $persona  desktop window tone: friendly | calm | playful (unknown values are ignored; see ReplyStyle)
     * @param  ?string  $source  'text' | 'voice' - voice means the answer is read aloud, so it is kept short and plain
     */
    public function __construct(
        public readonly string $tenantId,
        public readonly string $userId,
        public readonly ?string $conversationId,
        public readonly ?string $text,
        public readonly array $attachments = [],
        public readonly ?string $modelId = null,
        public readonly bool $useKnowledge = true,
        public readonly string $mode = 'general',
        public readonly ?string $characterId = null,
        public readonly ?string $persona = null,
        public readonly ?string $source = null,
    ) {}
}
