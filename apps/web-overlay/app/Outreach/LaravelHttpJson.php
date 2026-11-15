<?php

declare(strict_types=1);

namespace App\Outreach;

use Illuminate\Support\Facades\Http;

/** Only https, no redirects, short timeouts. Never logs a request or response (they carry tokens). */
final class LaravelHttpJson implements HttpJson
{
    public function request(string $method, string $url, array $headers = [], ?array $form = null, ?string $body = null): array
    {
        if (! str_starts_with($url, 'https://')) {
            throw new \InvalidArgumentException('https only');
        }
        $req = Http::withHeaders($headers)->timeout(25)->connectTimeout(10)->withoutRedirecting();
        $res = $form !== null ? $req->asForm()->send($method, $url, ['form_params' => $form]) : $req->send($method, $url, $body !== null ? ['body' => $body] : []);
        $json = $res->json();

        return ['status' => $res->status(), 'json' => is_array($json) ? $json : []];
    }
}
