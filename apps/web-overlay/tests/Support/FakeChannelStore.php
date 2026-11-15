<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Messaging\ChannelStore;

final class FakeChannelStore implements ChannelStore
{
    /** @var array<string, array<string, mixed>> external user id => link */
    public array $links = [];

    /** @var array<string, array{tenant: string, user: string, used: bool}> code hash => code */
    public array $codes = [];

    /** @var array<int, array<string, mixed>> */
    public array $events = [];

    public array $conversationSets = [];

    public array $revoked = [];

    public function authenticate(string $accountId, string $secret): bool
    {
        return $secret === 'good';
    }

    public function claim(string $accountId, int $updateId, string $kind): string
    {
        if (isset($this->events[$updateId])) {
            return 'duplicate';
        }
        $this->events[$updateId] = ['outcome' => 'processing'];

        return 'new';
    }

    public function finish(string $accountId, int $updateId, string $outcome, ?string $errorCode, ?string $tenantId, ?string $linkId): void
    {
        $this->events[$updateId] = ['outcome' => $outcome, 'code' => $errorCode, 'tenant' => $tenantId, 'link' => $linkId];
    }

    public function resolve(string $accountId, string $externalUserId): ?array
    {
        $l = $this->links[$externalUserId] ?? null;

        return ($l === null || ($l['revoked'] ?? false)) ? null : $l;
    }

    public function redeem(string $accountId, string $codeHash, string $externalUserId, string $externalChatId, ?string $username): array
    {
        $c = $this->codes[$codeHash] ?? null;
        if ($c === null || $c['used']) {
            return ['ok' => false, 'reason' => 'invalid_code', 'tenant_id' => null, 'user_id' => null];
        }
        foreach ($this->links as $l) {
            if ($l['user_id'] === $c['user'] && ! ($l['revoked'] ?? false)) {
                return ['ok' => false, 'reason' => 'user_already_linked', 'tenant_id' => null, 'user_id' => null];
            }
        }
        $this->codes[$codeHash]['used'] = true;
        $this->links[$externalUserId] = ['link_id' => 'LINK'.count($this->links), 'tenant_id' => $c['tenant'], 'user_id' => $c['user'], 'chat_id' => $externalChatId, 'conversation_id' => null];

        return ['ok' => true, 'reason' => 'ok', 'tenant_id' => $c['tenant'], 'user_id' => $c['user']];
    }

    public function setConversation(string $tenantId, string $linkId, ?string $conversationId): void
    {
        $this->conversationSets[] = [$linkId, $conversationId];
        foreach ($this->links as $k => $l) {
            if ($l['link_id'] === $linkId) {
                $this->links[$k]['conversation_id'] = $conversationId;
            }
        }
    }

    public function touch(string $tenantId, string $linkId): void {}

    public function revoke(string $tenantId, string $linkId): void
    {
        $this->revoked[] = $linkId;
        foreach ($this->links as $k => $l) {
            if ($l['link_id'] === $linkId) {
                $this->links[$k]['revoked'] = true;
            }
        }
    }

    public function health(string $accountId, ?string $error): void {}
}
