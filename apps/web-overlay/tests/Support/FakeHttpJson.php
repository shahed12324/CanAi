<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Outreach\HttpJson;

/** Replays queued answers and records every request so tests can inspect what would have gone over the wire. */
final class FakeHttpJson implements HttpJson
{
    public array $calls = [];

    /** @var list<array{status: int, json: array}> */
    public array $queue = [];

    public function request(string $method, string $url, array $headers = [], ?array $form = null, ?string $body = null): array
    {
        $this->calls[] = compact('method', 'url', 'headers', 'form', 'body');

        return array_shift($this->queue) ?? ['status' => 500, 'json' => []];
    }
}
