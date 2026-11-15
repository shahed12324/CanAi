<?php

declare(strict_types=1);

namespace App\Social;

use App\Outreach\HttpJson;
use Illuminate\Support\Facades\Http;

/** Like LaravelHttpJson but with time for uploading a picture or video.  Only https, no redirects, never logs. */
final class LongHttpJson implements HttpJson
{
    public function request(string $method, string $url, array $headers = [], ?array $form = null, ?string $body = null): array
    {
        if (! str_starts_with($url, 'https://')) {
            throw new \InvalidArgumentException('https only');
        }
        $req = Http::withHeaders($headers)->timeout(180)->connectTimeout(15)->withoutRedirecting();
        $res = $form !== null ? $req->asForm()->send($method, $url, ['form_params' => $form]) : $req->send($method, $url, $body !== null ? ['body' => $body] : []);
        $json = $res->json();

        return ['status' => $res->status(), 'json' => is_array($json) ? $json : []];
    }
}
