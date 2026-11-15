<?php

declare(strict_types=1);

namespace App\Chat;

/** Persistence needed by the chat flow (Postgres in production, an array in tests). */
interface ChatStore
{
    /** @return array{id: string, title: string, model_id: ?string, mode: string, character_id: ?string}|null  only the user's own, non-deleted conversation */
    public function conversation(string $tenantId, string $userId, string $conversationId): ?array;

    public function createConversation(string $tenantId, string $userId, string $title, ?string $modelId, string $mode = 'general', ?string $characterId = null): string;

    /** Visible (not hidden) complete/partial messages, oldest first, with their text attachments. @return list<array{id: string, role: string, content: string, attachments: list<array{filename: string, text: string}>}> */
    public function history(string $tenantId, string $conversationId): array;

    /** @param list<array{filename: string, mime: string, size: int, text: string}> $attachments */
    public function addUserMessage(string $tenantId, string $userId, string $conversationId, string $text, array $attachments): string;

    public function startAssistant(string $tenantId, string $conversationId, string $modelId, string $providerId): string;

    public function progress(string $messageId, string $content): void;

    public function finish(string $messageId, string $status, string $content, ?string $errorCode, ?int $inputTokens, ?int $outputTokens, string $creditsCharged, ?string $reservationId): void;

    public function touch(string $conversationId): void;

    /** Hide a finished message (regenerate replaces it). */
    public function hide(string $messageId): void;
}
