<?php

declare(strict_types=1);

namespace App\Couriers;

use App\Outreach\HttpJson;

/** Shared HTTP handling: secrets never reach an error message; a 4xx is a definite refusal, a 5xx / network error is "we do not know". */
abstract class BaseCourier implements CourierAdapter
{
    /** @param list<string> $secrets values to scrub from any message */
    public function __construct(protected readonly HttpJson $http, private readonly array $secrets = []) {}

    /** @param array<string, string> $headers @param array<string, mixed>|null $json @return array<string, mixed> */
    protected function send(string $method, string $url, array $headers, ?array $json = null, bool $beforeCreate = false): array
    {
        $headers += ['Accept' => 'application/json'];
        $body = null;
        if ($json !== null) {
            $headers['Content-Type'] = 'application/json';
            $body = json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        }
        try {
            $r = $this->http->request($method, $url, $headers, null, $body);
        } catch (\Throwable) {
            throw new CourierException('network error', ! $beforeCreate);
        }
        $s = $r['status'];
        if ($s >= 500 || $s === 429 || $s === 0) {
            throw new CourierException("courier HTTP $s", ! $beforeCreate);
        }
        if ($s < 200 || $s >= 300) {
            throw new CourierException("courier refused (HTTP $s) ".$this->message($r['json']));
        }

        return $r['json'];
    }

    /** @param array<string, mixed> $j */
    protected function message(array $j): string
    {
        $m = $j['message'] ?? $j['error'] ?? $j['errors'] ?? '';
        $m = is_string($m) ? $m : (json_encode($m, JSON_UNESCAPED_UNICODE) ?: '');
        foreach ($this->secrets as $s) {
            if ($s !== '') {
                $m = str_replace($s, '***', $m);
            }
        }

        return mb_substr($m, 0, 120);
    }

    protected static function taka(int|float $v): int|float
    {
        return $v;
    }
}
