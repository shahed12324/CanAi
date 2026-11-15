<?php

declare(strict_types=1);

namespace App\Inbox;

/** Everything the inbox needs from storage. Writes to conversations/messages happen only through the database functions behind this. */
interface InboxRepo
{
    /** The one lookup that needs no workspace: public key -> channel (no secrets). @return array<string, mixed>|null */
    public function channelByKey(string $key): ?array;

    /** @return list<array<string, mixed>> */
    public function channels(): array;

    /** @param  list<string>  $origins @return array{id: string, public_key: string} */
    public function createChannel(string $tenantId, string $kind, string $name, array $origins, ?string $greeting): array;

    /** @param  list<string>  $origins */
    public function updateChannel(string $id, string $name, string $status, array $origins, ?string $greeting): void;

    /** @return array{result: string, contact_id: ?string, conversation_id: ?string} */
    public function contactEnsure(string $channelId, string $externalId, ?string $name, ?string $tokenHash): array;

    public function contactVerify(string $channelId, string $externalId, string $tokenHash): ?string;

    /** @return array{result: string, seq: ?int} */
    public function receive(string $conversationId, string $body, ?string $externalId): array;

    /** @return array{result: string, seq: ?int} */
    public function send(string $conversationId, ?string $userId, string $sender, string $body): array;

    public function setMode(string $conversationId, ?string $userId, string $mode, ?string $reason): void;

    public function resolve(string $conversationId, ?string $userId, bool $resolved): void;

    public function markRead(string $conversationId): void;

    /** @param 'open'|'resolved'|'human'|'unread'|'mine' $filter @return list<array<string, mixed>> */
    public function conversations(string $filter, ?string $userId, int $limit = 100): array;

    /** @return array<string, mixed>|null */
    public function conversation(string $id): ?array;

    /** @return list<array<string, mixed>> messages with seq > $afterSeq, oldest first */
    public function messages(string $conversationId, int $afterSeq = 0, int $limit = 200, bool $outboundOnly = false): array;

    /** Sealed channel secret for webhooks / sending. @return array{cipher: string, version: int, kind: string, external_ref: string}|null */
    public function credentials(string $channelId): ?array;

    /** @param array{cipher: string, version: int} $sealed @return array{id: string, public_key: string} */
    public function createExternalChannel(string $tenantId, string $kind, string $name, string $accountRef, array $sealed, string $publicKey): array;

    /** Everything needed to deliver one queued message, incl. the rules that must hold at send time. @return array<string, mixed>|null */
    public function dispatchContext(int $seq): ?array;

    public function messageStatus(int $seq, string $status, ?string $error): bool;

    /** @return 'retry'|'failed'|'not_queued' */
    public function messageRetry(int $seq, string $error): string;
}
