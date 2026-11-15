<?php

declare(strict_types=1);

namespace App\Messaging;

/** Persistence for the gateway. Webhook-side calls run with no tenant; tenant-scoped calls carry the tenant id explicitly. */
interface ChannelStore
{
    public function authenticate(string $accountId, string $secret): bool;

    /** 'new' = process it, 'duplicate' = already handled or in progress. */
    public function claim(string $accountId, int $updateId, string $kind): string;

    public function finish(string $accountId, int $updateId, string $outcome, ?string $errorCode, ?string $tenantId, ?string $linkId): void;

    /** @return array{link_id: string, tenant_id: string, user_id: string, chat_id: string, conversation_id: ?string}|null  active members only */
    public function resolve(string $accountId, string $externalUserId): ?array;

    /** @return array{ok: bool, reason: string, tenant_id: ?string, user_id: ?string} */
    public function redeem(string $accountId, string $codeHash, string $externalUserId, string $externalChatId, ?string $username): array;

    public function setConversation(string $tenantId, string $linkId, ?string $conversationId): void;

    public function touch(string $tenantId, string $linkId): void;

    public function revoke(string $tenantId, string $linkId): void;

    public function health(string $accountId, ?string $error): void;
}
