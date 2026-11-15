<?php

declare(strict_types=1);

namespace App\Developer;

interface WebhookSender
{
    /**
     * Posts the body to the address.  $ip is the already-checked public address the name must be reached at (so a name cannot change its mind between the check and the send).
     *
     * @param  array<string, string>  $headers
     * @return array{code: int, error: ?string} code 0 when nothing came back
     */
    public function post(string $url, string $ip, array $headers, string $body): array;

    /** @return list<string> the IPv4 addresses of a host name */
    public function resolve(string $host): array;
}
