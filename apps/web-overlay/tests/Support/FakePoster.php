<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Links\WebhookPoster;

final class FakePoster implements WebhookPoster
{
    /** @var list<array{url: string, json: array<string, mixed>}> */
    public array $posts = [];

    public function __construct(public int $status = 204) {}

    public function post(string $url, array $json): int
    {
        $this->posts[] = compact('url', 'json');

        return $this->status;
    }
}
