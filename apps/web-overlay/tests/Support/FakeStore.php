<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Chat\ChatStore;

final class FakeStore implements ChatStore
{
    /** @var array<string, array<string, mixed>> */
    public array $conversations = [];

    /** @var array<string, array<string, mixed>> */
    public array $messages = [];

    private int $n = 0;

    private function id(string $p): string
    {
        return str_pad($p.(++$this->n), 26, '0', STR_PAD_LEFT);
    }

    public function conversation(string $tenantId, string $userId, string $conversationId): ?array
    {
        $c = $this->conversations[$conversationId] ?? null;

        return ($c !== null && $c['tenant'] === $tenantId && $c['user'] === $userId) ? ['id' => $conversationId, 'title' => $c['title'], 'model_id' => null, 'mode' => $c['mode'] ?? 'general', 'character_id' => $c['character'] ?? null] : null;
    }

    public function createConversation(string $tenantId, string $userId, string $title, ?string $modelId, string $mode = 'general', ?string $characterId = null): string
    {
        $id = $this->id('c');
        $this->conversations[$id] = ['tenant' => $tenantId, 'user' => $userId, 'title' => $title, 'mode' => $mode, 'character' => $characterId];

        return $id;
    }

    public function history(string $tenantId, string $conversationId): array
    {
        $out = [];
        foreach ($this->messages as $id => $m) {
            if ($m['conversation'] === $conversationId && $m['hidden'] === false && in_array($m['status'], ['complete', 'partial'], true)) {
                $out[] = ['id' => $id, 'role' => $m['role'], 'content' => $m['content'], 'attachments' => $m['attachments']];
            }
        }

        return $out;
    }

    public function addUserMessage(string $tenantId, string $userId, string $conversationId, string $text, array $attachments): string
    {
        $id = $this->id('u');
        $this->messages[$id] = ['conversation' => $conversationId, 'role' => 'user', 'content' => $text, 'status' => 'complete', 'hidden' => false,
            'attachments' => array_map(fn ($a) => ['filename' => $a['filename'], 'text' => $a['text']], $attachments), 'credits' => '0.0000', 'error' => null];

        return $id;
    }

    public function startAssistant(string $tenantId, string $conversationId, string $modelId, string $providerId): string
    {
        $id = $this->id('a');
        $this->messages[$id] = ['conversation' => $conversationId, 'role' => 'assistant', 'content' => '', 'status' => 'streaming', 'hidden' => false,
            'attachments' => [], 'credits' => '0.0000', 'error' => null];

        return $id;
    }

    public function progress(string $messageId, string $content): void
    {
        $this->messages[$messageId]['content'] = $content;
    }

    public function finish(string $messageId, string $status, string $content, ?string $errorCode, ?int $inputTokens, ?int $outputTokens, string $creditsCharged, ?string $reservationId): void
    {
        $this->messages[$messageId] = ['status' => $status, 'content' => $content, 'error' => $errorCode, 'credits' => $creditsCharged, 'in' => $inputTokens, 'out' => $outputTokens] + $this->messages[$messageId];
    }

    public function touch(string $conversationId): void {}

    public function hide(string $messageId): void
    {
        $this->messages[$messageId]['hidden'] = true;
    }

    /** @return array<string, mixed>|null */
    public function lastAssistant(): ?array
    {
        $a = array_filter($this->messages, fn ($m) => $m['role'] === 'assistant');

        return $a === [] ? null : end($a);
    }
}
