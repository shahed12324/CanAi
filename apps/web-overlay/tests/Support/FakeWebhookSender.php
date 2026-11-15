<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Developer\WebhookSender;

final class FakeWebhookSender implements WebhookSender
{
    /** @var array<string, list<string>> */
    public array $dns = [];

    /** @var list<array<string, mixed>> */
    public array $posts = [];

    /** @var array{code: int, error: ?string} */
    public array $answer = ['code' => 200, 'error' => null];

    public ?\Throwable $throw = null;

    public function post(string $url, string $ip, array $headers, string $body): array
    {
        if ($this->throw !== null) {
            throw $this->throw;
        }
        $this->posts[] = compact('url', 'ip', 'headers', 'body');

        return $this->answer;
    }

    public function resolve(string $host): array
    {
        return $this->dns[$host] ?? [];
    }
}
