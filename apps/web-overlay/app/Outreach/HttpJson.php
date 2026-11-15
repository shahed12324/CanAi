<?php

declare(strict_types=1);

namespace App\Outreach;

interface HttpJson
{
    /**
     * @param  array<string, string>  $headers
     * @param  array<string, string>|null  $form  sent as application/x-www-form-urlencoded
     * @param  string|null  $body  raw body (the Content-Type is then in $headers)
     * @return array{status: int, json: array<string, mixed>}
     */
    public function request(string $method, string $url, array $headers = [], ?array $form = null, ?string $body = null): array;
}
