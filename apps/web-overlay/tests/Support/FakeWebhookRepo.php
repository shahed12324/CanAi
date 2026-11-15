<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Developer\WebhookRepo;

final class FakeWebhookRepo implements WebhookRepo
{
    /** @var list<array<string, mixed>> */
    public array $created = [];

    public ?string $createResult = null;

    public int $enqueued = 0;

    /** @var list<array<string, mixed>> */
    public array $queue = [];

    /** @var list<array<string, mixed>> */
    public array $results = [];

    public function create(string $tenantId, string $userId, string $id, string $name, string $url, string $cipher, int $version): string
    {
        if ($this->createResult !== null) {
            return $this->createResult;
        }
        $this->created[] = compact('tenantId', 'userId', 'id', 'name', 'url', 'cipher', 'version');

        return 'ok';
    }

    public function delete(string $tenantId, string $userId, string $id): string
    {
        return 'ok';
    }

    public function setActive(string $tenantId, string $userId, string $id, bool $active): string
    {
        return 'ok';
    }

    /** @var list<array<string, mixed>> */
    public array $eventCalls = [];

    /** @var list<array<string, mixed>> */
    public array $messages = [];

    public string $messageResult = 'ok';

    public function sendMessage(string $tenantId, string $userId, string $name, string $text): string
    {
        $this->messages[] = compact('tenantId', 'userId', 'name', 'text');

        return $this->messageResult;
    }

    public function setEvents(string $tenantId, string $userId, string $id, array $events): string
    {
        $this->eventCalls[] = compact('tenantId', 'userId', 'id', 'events');

        return 'ok';
    }

    public function list(string $userId): array
    {
        return [];
    }

    public function deliveries(string $userId): array
    {
        return [];
    }

    public function enqueue(): int
    {
        return $this->enqueued;
    }

    public function claim(int $limit): array
    {
        $out = array_slice($this->queue, 0, $limit);
        $this->queue = array_slice($this->queue, $limit);

        return $out;
    }

    public function result(string $id, bool $ok, ?int $code, ?string $error): void
    {
        $this->results[] = compact('id', 'ok', 'code', 'error');
    }
}
