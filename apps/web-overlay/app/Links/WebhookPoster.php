<?php

declare(strict_types=1);

namespace App\Links;

interface WebhookPoster
{
    /** @param array<string, mixed> $json @return int the HTTP status (0 = could not connect) */
    public function post(string $url, array $json): int;
}
