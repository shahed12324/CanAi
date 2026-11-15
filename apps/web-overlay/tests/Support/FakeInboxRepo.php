<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Inbox\InboxRepo;

final class FakeInboxRepo implements InboxRepo
{
    public array $channel = ['id' => 'CH', 'tenant_id' => 'T', 'kind' => 'web_widget', 'status' => 'active', 'allowed_origins' => ['https://shop.example.com'], 'greeting' => 'হ্যালো', 'name' => 'Site'];

    /** @var array<string, array{conv: string, hash: string}> */
    public array $visitors = [];

    public array $messages = [];

    public array $received = [];

    public int $seq = 0;

    public string $nextResult = 'received';

    public bool $limited = false;

    public function channelByKey(string $key): ?array
    {
        return $key === str_repeat('a', 32) ? $this->channel : null;
    }

    public function channels(): array
    {
        return [$this->channel];
    }

    public function createChannel(string $tenantId, string $kind, string $name, array $origins, ?string $greeting): array
    {
        return ['id' => 'NEW', 'public_key' => str_repeat('b', 32)];
    }

    public function updateChannel(string $id, string $name, string $status, array $origins, ?string $greeting): void {}

    public function contactEnsure(string $channelId, string $externalId, ?string $name, ?string $tokenHash): array
    {
        if ($this->limited) {
            return ['result' => 'rate_limited', 'contact_id' => null, 'conversation_id' => null];
        }
        $this->visitors[$externalId] = $this->visitors[$externalId] ?? ['conv' => 'CONV'.count($this->visitors), 'hash' => (string) $tokenHash];
        $this->contacts[$externalId] = $name;

        return ['result' => 'ok', 'contact_id' => 'C', 'conversation_id' => $this->visitors[$externalId]['conv']];
    }

    public function contactVerify(string $channelId, string $externalId, string $tokenHash): ?string
    {
        $v = $this->visitors[$externalId] ?? null;

        return $v !== null && hash_equals($v['hash'], $tokenHash) ? $v['conv'] : null;
    }

    public function receive(string $conversationId, string $body, ?string $externalId): array
    {
        if ($externalId !== null && in_array([$conversationId, $externalId], array_map(fn ($r) => [$r['conv'], $r['ext']], $this->received), true)) {
            return ['result' => 'duplicate', 'seq' => null];
        }
        if ($this->nextResult !== 'received') {
            return ['result' => $this->nextResult, 'seq' => null];
        }
        $this->received[] = ['conv' => $conversationId, 'body' => $body, 'ext' => $externalId];

        return ['result' => 'received', 'seq' => ++$this->seq];
    }

    public function send(string $conversationId, ?string $userId, string $sender, string $body): array
    {
        if ($this->sendResult !== null) {
            return ['result' => $this->sendResult, 'seq' => null];
        }
        $this->messages[] = ['seq' => ++$this->seq, 'conv' => $conversationId, 'direction' => 'out', 'sender' => $sender, 'user_id' => $userId, 'body' => $body, 'status' => 'sent', 'error' => null, 'created_at' => '2026-11-03 10:00:00'];

        return ['result' => 'sent', 'seq' => $this->seq];
    }

    public array $modes = [];

    public ?string $sendResult = null;

    public function setMode(string $conversationId, ?string $userId, string $mode, ?string $reason): void
    {
        $this->modes[] = [$conversationId, $mode, $reason];
    }

    public function resolve(string $conversationId, ?string $userId, bool $resolved): void {}

    public function markRead(string $conversationId): void {}

    public function conversations(string $filter, ?string $userId, int $limit = 100): array
    {
        return [];
    }

    public ?array $conversationRow = null;

    public function conversation(string $id): ?array
    {
        return $this->conversationRow;
    }

    public array $contacts = [];

    public array $statuses = [];

    public array $retries = [];

    public ?array $ctx = null;

    public string $retryResult = 'retry';

    public function credentials(string $channelId): ?array
    {
        return ['cipher' => 'c', 'version' => 1, 'kind' => $this->channel['kind'], 'external_ref' => (string) ($this->channel['external_ref'] ?? '')];
    }

    public function createExternalChannel(string $tenantId, string $kind, string $name, string $accountRef, array $sealed, string $publicKey): array
    {
        return ['id' => 'EXT', 'public_key' => $publicKey];
    }

    public function dispatchContext(int $seq): ?array
    {
        return $this->ctx;
    }

    public function messageStatus(int $seq, string $status, ?string $error): bool
    {
        $this->statuses[] = [$seq, $status, $error];

        return true;
    }

    public function messageRetry(int $seq, string $error): string
    {
        $this->retries[] = [$seq, $error];

        return $this->retryResult;
    }

    public function messages(string $conversationId, int $afterSeq = 0, int $limit = 200, bool $outboundOnly = false): array
    {
        return array_values(array_filter($this->messages, fn ($m) => $m['conv'] === $conversationId && $m['seq'] > $afterSeq && (! $outboundOnly || $m['direction'] === 'out')));
    }
}
