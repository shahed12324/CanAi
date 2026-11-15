<?php

declare(strict_types=1);

namespace App\Links;

use Illuminate\Support\Facades\Http;

/** The address was checked by WebhookUrl (only the two services' own hosts); still no redirects, short timeouts, nothing logged (the address is a secret). */
final class HttpWebhookPoster implements WebhookPoster
{
    public function post(string $url, array $json): int
    {
        if (WebhookUrl::parse($url) === null) {
            return 0;
        }
        try {
            return Http::timeout(10)->connectTimeout(5)->withoutRedirecting()->asJson()->post($url, $json)->status();
        } catch (\Throwable $e) {
            return 0;
        }
    }
}
