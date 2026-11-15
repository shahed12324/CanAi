<?php

declare(strict_types=1);

namespace App\Catalog\Stores;

use App\Outreach\HttpJson;

/**
 * Shopify Admin REST API (custom-app access token). Paged by `since_id`. Variant external id = Shopify variant id.
 * A product with only the "Default Title" variant is a single product (options {}). Prices: Shopify keeps price + compare_at_price;
 * we read compare_at > price as regular + sale. The API version is configurable (config('catalog.shopify_api_version')).
 */
final class ShopifyAdapter extends BaseAdapter
{
    public const PER_PAGE = 100;

    private ?int $location = null;

    public function __construct(HttpJson $http, private readonly string $shop, private readonly string $token, private readonly string $currency = 'BDT', private readonly string $version = '2026-07')
    {
        parent::__construct($http);
    }

    public static function validShop(string $host): bool
    {
        return preg_match('/\A[a-z0-9][a-z0-9-]{0,60}\.myshopify\.com\z/', strtolower($host)) === 1;
    }

    private function url(string $path): string
    {
        if (! self::validShop($this->shop)) {
            throw new StoreException('invalid', 'The store address must be of the form xxx.myshopify.com.');
        }

        return 'https://'.strtolower($this->shop).'/admin/api/'.$this->version.'/'.ltrim($path, '/');
    }

    /** @return array<string, string> */
    private function auth(): array
    {
        return ['X-Shopify-Access-Token' => $this->token];
    }

    public function ping(): string
    {
        $r = $this->call('GET', $this->url('shop.json'), $this->auth());

        return (string) ($r['shop']['name'] ?? $this->shop);
    }

    public function page(?string $cursor): array
    {
        $r = $this->call('GET', $this->url('products.json?limit='.self::PER_PAGE.'&since_id='.max(0, (int) $cursor)), $this->auth());
        $rows = array_values(array_filter($r['products'] ?? [], fn ($p) => is_array($p) && isset($p['id'])));
        $items = array_map(fn ($p) => $this->map($p), $rows);

        return ['items' => $items, 'next' => count($rows) >= self::PER_PAGE ? (string) $rows[count($rows) - 1]['id'] : null];
    }

    public function fetch(string $externalId): ?array
    {
        try {
            $r = $this->call('GET', $this->url('products/'.rawurlencode($externalId).'.json'), $this->auth());
        } catch (StoreException $e) {
            if ($e->errorCode === 'not_found') {
                return null;
            }
            throw $e;
        }

        return isset($r['product']['id']) ? $this->map($r['product']) : null;
    }

    /** @param array<string, mixed> $p */
    private function map(array $p): array
    {
        $names = array_map(fn ($o) => (string) ($o['name'] ?? ''), $p['options'] ?? []);
        $vs = [];
        $rows = array_values(array_filter($p['variants'] ?? [], 'is_array'));
        $single = count($rows) === 1 && ($rows[0]['option1'] ?? null) === 'Default Title';
        $price = 0;
        $sale = null;
        foreach ($rows as $i => $v) {
            $opts = [];
            if (! $single) {
                foreach ([1, 2, 3] as $n) {
                    $val = $v["option$n"] ?? null;
                    if ($val !== null && $val !== '' && ($names[$n - 1] ?? '') !== '') {
                        $opts[$names[$n - 1]] = (string) $val;
                    }
                }
            }
            $vp = self::minor($v['price'] ?? null) ?? 0;
            $cmp = self::minor($v['compare_at_price'] ?? null);
            $reg = $cmp !== null && $cmp > $vp ? $cmp : $vp;
            if ($i === 0) {
                $price = $reg;
                $sale = $cmp !== null && $cmp > $vp ? $vp : null;
            }
            $vs[] = ['external_id' => (string) $v['id'], 'sku' => ($v['sku'] ?? '') === '' ? null : (string) $v['sku'], 'options' => $opts,
                'price_minor' => $i === 0 || $reg === $price ? null : $reg, 'stock' => ($v['inventory_management'] ?? null) === null ? null : max(0, (int) ($v['inventory_quantity'] ?? 0))];
        }
        $tags = array_values(array_filter(array_map('trim', explode(',', (string) ($p['tags'] ?? '')))));

        return [
            'external_id' => (string) $p['id'], 'title' => trim((string) ($p['title'] ?? '')) ?: 'Product '.$p['id'], 'description' => self::plain($p['body_html'] ?? ''),
            'price_minor' => $price, 'sale_price_minor' => $sale, 'currency' => $this->currency, 'sku' => $single ? ($vs[0]['sku'] ?? null) : null,
            'category' => ($p['product_type'] ?? '') === '' ? null : (string) $p['product_type'], 'tags' => $tags, 'images' => self::urls($p['images'] ?? [], 'src'),
            'variants' => $vs === [] ? [['external_id' => (string) $p['id'], 'sku' => null, 'options' => [], 'price_minor' => null, 'stock' => null]] : $vs,
        ];
    }

    public function push(array $product, ?string $externalId): string
    {
        $vs = $product['variants'];
        $single = count($vs) === 1 && $vs[0]['options'] === [];
        $names = [];
        foreach ($vs as $v) {
            foreach (array_keys($v['options']) as $n) {
                $names[$n] = true;
            }
        }
        $names = array_slice(array_keys($names), 0, 3);
        $have = [];
        if ($externalId !== null) {
            foreach ($this->fetch($externalId)['variants'] ?? [] as $rv) {
                $have[self::sig($rv['options'])] = $rv['external_id'];
            }
        }
        $variants = [];
        foreach ($vs as $v) {
            $reg = $v['price_minor'] ?? $product['price_minor'];
            $sale = $v['price_minor'] === null ? ($product['sale_price_minor'] ?? null) : null;
            $row = ['sku' => $v['sku'] ?? ($single ? ($product['sku'] ?? '') : ''), 'price' => self::major($sale ?? $reg), 'compare_at_price' => $sale !== null ? self::major($reg) : null,
                'inventory_management' => $v['stock'] !== null ? 'shopify' : null];
            if ($single) {
                $row['option1'] = 'Default Title';
            }
            foreach ($names as $i => $n) {
                $row['option'.($i + 1)] = $v['options'][$n] ?? 'Default';
            }
            $ext = $have[self::sig($v['options'])] ?? null;
            if ($ext !== null) {
                $row['id'] = (int) $ext;
            } elseif ($v['stock'] !== null) {
                $row['inventory_quantity'] = (int) $v['stock'];
            }
            $variants[] = $row;
        }
        $body = ['product' => array_filter([
            'title' => $product['title'], 'body_html' => self::html((string) $product['description']), 'product_type' => $product['category'] ?? '',
            'tags' => implode(', ', $product['tags'] ?? []), 'images' => array_map(fn ($u) => ['src' => $u], $product['images']),
            'options' => $single ? null : array_map(fn ($n) => ['name' => $n], $names), 'variants' => $variants,
        ], fn ($x) => $x !== null)];
        $r = $externalId === null
            ? $this->call('POST', $this->url('products.json'), $this->auth(), $body)
            : $this->call('PUT', $this->url('products/'.rawurlencode($externalId).'.json'), $this->auth(), ['product' => ['id' => (int) $externalId] + $body['product']]);
        $id = (string) ($r['product']['id'] ?? '');
        if ($id === '') {
            throw new StoreException('bad_response', 'no product id');
        }

        return $id;
    }

    public function pushStock(string $variantExternalId, int $qty): void
    {
        $v = $this->call('GET', $this->url('variants/'.rawurlencode($variantExternalId).'.json'), $this->auth());
        $item = $v['variant']['inventory_item_id'] ?? null;
        if ($item === null) {
            throw new StoreException('bad_response', 'no inventory item');
        }
        if ($this->location === null) {
            $l = $this->call('GET', $this->url('locations.json'), $this->auth());
            $first = null;
            foreach ($l['locations'] ?? [] as $loc) {
                if (! empty($loc['active'])) {
                    $first = (int) $loc['id'];
                    break;
                }
            }
            $this->location = $first ?? throw new StoreException('bad_response', 'no active location');
        }
        $this->call('POST', $this->url('inventory_levels/set.json'), $this->auth(), ['location_id' => $this->location, 'inventory_item_id' => (int) $item, 'available' => $qty]);
    }
}
