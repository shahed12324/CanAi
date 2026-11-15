<?php

declare(strict_types=1);

namespace App\Catalog\Stores;

use App\Outreach\HttpJson;

/**
 * Talks to a shop that has the "JARVIS Connector" Laravel package installed. Every request is signed (HMAC-SHA256 with the shared secret,
 * timestamp + single-use nonce), so the shop can tell it is really us and cannot be replayed. The shop's answers are treated as untrusted
 * and cleaned before they touch our catalog.
 */
final class ConnectorAdapter extends BaseAdapter
{
    public const PER_PAGE = 50;

    /** @param (callable(): int)|null $clock */
    public function __construct(HttpJson $http, private readonly string $baseUrl, private readonly string $secret, private readonly string $currency = 'BDT', private $clock = null)
    {
        parent::__construct($http);
    }

    private function send(string $method, string $route, ?array $payload = null): array
    {
        $u = parse_url(rtrim($this->baseUrl, '/'));
        $path = ($u['path'] ?? '').'/jarvis-connector/v1/'.$route;
        $url = ($u['scheme'] ?? 'https').'://'.($u['host'] ?? '').$path;
        $this->signer = function (string $m, string $fullUrl, string $body) use ($path): array {
            $ts = (string) ($this->clock !== null ? ($this->clock)() : time());
            $nonce = bin2hex(random_bytes(16));
            $parts = parse_url($fullUrl);
            $uri = ($parts['path'] ?? $path).(isset($parts['query']) ? '?'.$parts['query'] : '');

            return ['X-Jarvis-Timestamp' => $ts, 'X-Jarvis-Nonce' => $nonce, 'X-Jarvis-Signature' => ConnectorSignature::sign($this->secret, $ts, $nonce, $m, $uri, $body)];
        };

        return $this->call($method, $url, [], $payload);
    }

    public function ping(): string
    {
        $r = $this->send('GET', 'ping');
        if (($r['ok'] ?? false) !== true) {
            throw new StoreException('bad_response', 'unexpected ping answer');
        }

        return mb_substr((string) ($r['name'] ?? parse_url($this->baseUrl, PHP_URL_HOST)), 0, 120);
    }

    public function page(?string $cursor): array
    {
        $q = 'products?limit='.self::PER_PAGE.($cursor !== null && $cursor !== '' ? '&cursor='.rawurlencode($cursor) : '');
        $r = $this->send('GET', $q);
        $items = [];
        foreach (is_array($r['items'] ?? null) ? $r['items'] : [] as $raw) {
            $p = is_array($raw) ? $this->clean($raw) : null;
            if ($p !== null) {
                $items[] = $p;
            }
        }
        $next = $r['next'] ?? null;

        return ['items' => $items, 'next' => is_string($next) && $next !== '' && $next !== $cursor ? mb_substr($next, 0, 40) : null];
    }

    public function fetch(string $externalId): ?array
    {
        try {
            $r = $this->send('GET', 'products/'.rawurlencode($externalId));
        } catch (StoreException $e) {
            if ($e->errorCode === 'not_found') {
                return null;
            }
            throw $e;
        }

        return $this->clean($r);
    }

    public function push(array $product, ?string $externalId): string
    {
        $body = [
            'title' => $product['title'], 'description' => $product['description'], 'price_minor' => $product['price_minor'], 'sale_price_minor' => $product['sale_price_minor'] ?? null,
            'sku' => $product['sku'] ?? null, 'category' => $product['category'] ?? null, 'tags' => $product['tags'] ?? [], 'images' => $product['images'] ?? [],
            'variants' => array_map(fn ($v) => ['sku' => $v['sku'] ?? null, 'options' => (object) ($v['options'] ?? []), 'price_minor' => $v['price_minor'] ?? null, 'stock' => $v['stock'] ?? null], $product['variants']),
        ];
        $r = $externalId === null ? $this->send('POST', 'products', $body) : $this->send('PUT', 'products/'.rawurlencode($externalId), $body);
        $id = (string) ($r['external_id'] ?? '');
        if ($id === '' || strlen($id) > 60) {
            throw new StoreException('bad_response', 'no product id');
        }

        return $id;
    }

    public function pushStock(string $variantExternalId, int $qty): void
    {
        $this->send('POST', 'stock', ['variant_external_id' => $variantExternalId, 'qty' => max(0, $qty)]);
    }

    /** The shop is not trusted: only known fields, right types, https image links. @param array<string, mixed> $p */
    private function clean(array $p): ?array
    {
        $id = (string) ($p['external_id'] ?? '');
        if ($id === '' || strlen($id) > 60) {
            return null;
        }
        $variants = [];
        foreach (is_array($p['variants'] ?? null) ? array_slice($p['variants'], 0, 100) : [] as $v) {
            $vid = is_array($v) ? (string) ($v['external_id'] ?? '') : '';
            if ($vid === '' || strlen($vid) > 120) {
                continue;
            }
            $opts = [];
            foreach (is_array($v['options'] ?? null) ? array_slice($v['options'], 0, 3, true) : [] as $k => $val) {
                if (is_string($k) && is_scalar($val)) {
                    $opts[mb_substr($k, 0, 40)] = mb_substr((string) $val, 0, 80);
                }
            }
            $variants[] = ['external_id' => $vid, 'sku' => isset($v['sku']) && $v['sku'] !== '' ? mb_substr((string) $v['sku'], 0, 80) : null, 'options' => $opts,
                'price_minor' => isset($v['price_minor']) ? max(0, (int) $v['price_minor']) : null, 'stock' => isset($v['stock']) && $v['stock'] !== null ? max(0, (int) $v['stock']) : null];
        }
        $price = max(0, (int) ($p['price_minor'] ?? 0));
        $sale = isset($p['sale_price_minor']) ? (int) $p['sale_price_minor'] : null;

        return [
            'external_id' => $id, 'title' => mb_substr(trim((string) ($p['title'] ?? '')), 0, 250) ?: "Product $id", 'description' => mb_substr((string) ($p['description'] ?? ''), 0, 20000),
            'price_minor' => $price, 'sale_price_minor' => $sale !== null && $sale > 0 && $sale < $price ? $sale : null, 'currency' => $this->currency,
            'sku' => isset($p['sku']) && $p['sku'] !== '' ? mb_substr((string) $p['sku'], 0, 80) : null, 'category' => isset($p['category']) && $p['category'] !== '' ? mb_substr((string) $p['category'], 0, 120) : null,
            'tags' => array_slice(array_values(array_filter(array_map(fn ($t) => is_string($t) ? mb_substr(trim($t), 0, 40) : '', is_array($p['tags'] ?? null) ? $p['tags'] : []))), 0, 30),
            'images' => self::httpUrls($p['images'] ?? []), 'variants' => $variants === [] ? [['external_id' => $id, 'sku' => null, 'options' => [], 'price_minor' => null, 'stock' => null]] : $variants,
        ];
    }

    /** @return list<string> */
    private static function httpUrls(mixed $list): array
    {
        $out = [];
        foreach (is_array($list) ? $list : [] as $u) {
            if (is_string($u) && preg_match('#\Ahttps?://[^\s]{4,500}\z#i', $u) === 1) {
                $out[] = $u;
            }
        }

        return array_slice($out, 0, 10);
    }
}
