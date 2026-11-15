<?php

declare(strict_types=1);

namespace App\Catalog\Stores;

use App\Outreach\HttpJson;

/**
 * WooCommerce REST API v3 (consumer key + secret over HTTPS, HTTP Basic).
 * Variant external ids: simple product "123"; variation "123/456".
 * Limits: first 100 variations per variable product; categories and tags are read but not pushed (Woo wants their numeric ids).
 */
final class WooAdapter extends BaseAdapter
{
    public const PER_PAGE = 50;

    public function __construct(HttpJson $http, private readonly string $baseUrl, private readonly string $key, private readonly string $secret, private readonly string $currency = 'BDT')
    {
        parent::__construct($http);
    }

    private function url(string $path): string
    {
        return rtrim($this->baseUrl, '/').'/wp-json/wc/v3/'.ltrim($path, '/');
    }

    /** @return array<string, string> */
    private function auth(): array
    {
        return ['Authorization' => 'Basic '.base64_encode($this->key.':'.$this->secret)];
    }

    public function ping(): string
    {
        $this->call('GET', $this->url('products?per_page=1'), $this->auth());

        return (string) (parse_url($this->baseUrl, PHP_URL_HOST) ?: 'WooCommerce');
    }

    public function page(?string $cursor): array
    {
        $page = max(1, (int) ($cursor ?? 1));
        $rows = $this->call('GET', $this->url('products?per_page='.self::PER_PAGE.'&orderby=id&order=asc&page='.$page), $this->auth());
        $items = [];
        foreach ($rows as $r) {
            if (is_array($r) && isset($r['id'])) {
                $items[] = $this->map($r);
            }
        }

        return ['items' => $items, 'next' => count($rows) >= self::PER_PAGE ? (string) ($page + 1) : null];
    }

    public function fetch(string $externalId): ?array
    {
        try {
            $r = $this->call('GET', $this->url('products/'.rawurlencode($externalId)), $this->auth());
        } catch (StoreException $e) {
            if ($e->errorCode === 'not_found') {
                return null;
            }
            throw $e;
        }

        return isset($r['id']) ? $this->map($r) : null;
    }

    /** @param array<string, mixed> $p */
    private function map(array $p): array
    {
        $id = (string) $p['id'];
        $variants = [];
        if (($p['type'] ?? 'simple') === 'variable') {
            $vs = $this->call('GET', $this->url("products/$id/variations?per_page=100"), $this->auth());
            foreach ($vs as $v) {
                if (! is_array($v) || ! isset($v['id'])) {
                    continue;
                }
                $opts = [];
                foreach ($v['attributes'] ?? [] as $a) {
                    if (isset($a['name'], $a['option']) && $a['option'] !== '') {
                        $opts[(string) $a['name']] = (string) $a['option'];
                    }
                }
                $variants[] = ['external_id' => "$id/{$v['id']}", 'sku' => ($v['sku'] ?? '') === '' ? null : (string) $v['sku'], 'options' => $opts,
                    'price_minor' => self::minor($v['regular_price'] ?? null), 'stock' => ! empty($v['manage_stock']) ? (int) ($v['stock_quantity'] ?? 0) : null];
            }
        }
        if ($variants === []) {
            $variants[] = ['external_id' => $id, 'sku' => null, 'options' => [], 'price_minor' => null, 'stock' => ! empty($p['manage_stock']) ? (int) ($p['stock_quantity'] ?? 0) : null];
        }
        $price = self::minor($p['regular_price'] ?? null) ?? self::minor($p['price'] ?? null) ?? 0;
        $sale = self::minor($p['sale_price'] ?? null);

        return [
            'external_id' => $id, 'title' => trim((string) ($p['name'] ?? '')) ?: "Product $id", 'description' => self::plain($p['description'] ?? ''),
            'price_minor' => $price, 'sale_price_minor' => $sale !== null && $sale < $price ? $sale : null, 'currency' => $this->currency,
            'sku' => ($p['sku'] ?? '') === '' ? null : (string) $p['sku'],
            'category' => isset($p['categories'][0]['name']) ? (string) $p['categories'][0]['name'] : null,
            'tags' => array_values(array_filter(array_map(fn ($t) => is_array($t) ? (string) ($t['name'] ?? '') : '', $p['tags'] ?? []))),
            'images' => self::urls($p['images'] ?? [], 'src'), 'variants' => $variants,
        ];
    }

    public function push(array $product, ?string $externalId): string
    {
        $vs = $product['variants'];
        $variable = count($vs) > 1 || ($vs !== [] && $vs[0]['options'] !== []);
        $body = [
            'name' => $product['title'], 'description' => self::html((string) $product['description']), 'regular_price' => self::major($product['price_minor']),
            'sale_price' => self::major($product['sale_price_minor'] ?? null), 'images' => array_map(fn ($u) => ['src' => $u], $product['images']),
            'type' => $variable ? 'variable' : 'simple',
        ];
        if (! $variable) {
            $body['sku'] = $product['sku'] ?? '';
            $body['manage_stock'] = $vs[0]['stock'] !== null;
            if ($vs[0]['stock'] !== null) {
                $body['stock_quantity'] = (int) $vs[0]['stock'];
            }
        } else {
            $attrs = [];
            foreach ($vs as $v) {
                foreach ($v['options'] as $n => $val) {
                    $attrs[$n][$val] = true;
                }
            }
            $body['attributes'] = array_map(fn ($n) => ['name' => $n, 'visible' => true, 'variation' => true, 'options' => array_keys($attrs[$n])], array_keys($attrs));
        }
        $r = $externalId === null
            ? $this->call('POST', $this->url('products'), $this->auth(), $body)
            : $this->call('PUT', $this->url('products/'.rawurlencode($externalId)), $this->auth(), $body);
        $id = (string) ($r['id'] ?? '');
        if ($id === '') {
            throw new StoreException('bad_response', 'no product id');
        }
        if ($variable) {
            $have = [];
            if ($externalId !== null) {
                foreach ($this->fetch($externalId)['variants'] ?? [] as $rv) {
                    $have[self::sig($rv['options'])] = $rv['external_id'];
                }
            }
            $create = [];
            $update = [];
            foreach ($vs as $v) {
                $row = ['sku' => $v['sku'] ?? '', 'regular_price' => self::major($v['price_minor'] ?? $product['price_minor']), 'manage_stock' => $v['stock'] !== null,
                    'attributes' => array_map(fn ($n, $val) => ['name' => $n, 'option' => $val], array_keys($v['options']), array_values($v['options']))];
                if ($v['stock'] !== null) {
                    $row['stock_quantity'] = (int) $v['stock'];
                }
                $ext = $have[self::sig($v['options'])] ?? null;
                if ($ext !== null) {
                    $update[] = $row + ['id' => (int) substr((string) strrchr($ext, '/'), 1)];
                } else {
                    $create[] = $row;
                }
            }
            $this->call('POST', $this->url("products/$id/variations/batch"), $this->auth(), array_filter(['create' => $create, 'update' => $update]));
        }

        return $id;
    }

    public function pushStock(string $variantExternalId, int $qty): void
    {
        $body = ['manage_stock' => true, 'stock_quantity' => $qty];
        $path = str_contains($variantExternalId, '/')
            ? 'products/'.rawurlencode(explode('/', $variantExternalId)[0]).'/variations/'.rawurlencode(explode('/', $variantExternalId)[1])
            : 'products/'.rawurlencode($variantExternalId);
        $this->call('PUT', $this->url($path), $this->auth(), $body);
    }
}
