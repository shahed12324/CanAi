<?php

declare(strict_types=1);

namespace Jarvis\Connector;

/** Validates a product that JARVIS sends. Anything unexpected is rejected, never guessed. */
final class Canonical
{
    /**
     * @param  array<string, mixed>  $p
     * @return array<string, mixed>
     *
     * @throws \InvalidArgumentException
     */
    public static function clean(array $p): array
    {
        $title = trim((string) ($p['title'] ?? ''));
        if ($title === '' || mb_strlen($title) > 250) {
            throw new \InvalidArgumentException('title must be 1-250 characters');
        }
        $desc = (string) ($p['description'] ?? '');
        if (mb_strlen($desc) > 20000) {
            throw new \InvalidArgumentException('description too long');
        }
        $price = self::int($p['price_minor'] ?? null, 'price_minor', 100_000_000_000);
        if ($price === null) {
            throw new \InvalidArgumentException('price_minor is required');
        }
        $sale = self::int($p['sale_price_minor'] ?? null, 'sale_price_minor', 100_000_000_000);
        if ($sale !== null && $sale >= $price) {
            throw new \InvalidArgumentException('sale_price_minor must be below price_minor');
        }
        $sku = isset($p['sku']) && $p['sku'] !== '' ? (string) $p['sku'] : null;
        if ($sku !== null && mb_strlen($sku) > 80) {
            throw new \InvalidArgumentException('sku too long');
        }
        $images = [];
        foreach (is_array($p['images'] ?? null) ? $p['images'] : [] as $u) {
            if (! is_string($u) || preg_match('#\Ahttps?://[^\s]{4,500}\z#i', $u) !== 1) {
                throw new \InvalidArgumentException('images must be http(s) urls');
            }
            $images[] = $u;
        }
        $tags = [];
        foreach (is_array($p['tags'] ?? null) ? $p['tags'] : [] as $t) {
            if (is_string($t) && trim($t) !== '') {
                $tags[] = mb_substr(trim($t), 0, 40);
            }
        }
        $variants = [];
        $vs = $p['variants'] ?? [];
        if (! is_array($vs) || count($vs) > 100) {
            throw new \InvalidArgumentException('variants must be a list of at most 100');
        }
        foreach ($vs as $v) {
            if (! is_array($v)) {
                throw new \InvalidArgumentException('bad variant');
            }
            $opts = [];
            foreach (is_array($v['options'] ?? null) ? $v['options'] : [] as $k => $val) {
                if (! is_string($k) || ! is_scalar($val) || $k === '' || mb_strlen($k) > 40 || mb_strlen((string) $val) > 80) {
                    throw new \InvalidArgumentException('bad variant options');
                }
                $opts[$k] = (string) $val;
            }
            if (count($opts) > 3) {
                throw new \InvalidArgumentException('at most 3 options');
            }
            ksort($opts);
            $stock = self::int($v['stock'] ?? null, 'stock', 100_000_000);
            $variants[] = ['sku' => isset($v['sku']) && $v['sku'] !== '' ? mb_substr((string) $v['sku'], 0, 80) : null, 'options' => $opts,
                'price_minor' => self::int($v['price_minor'] ?? null, 'variant price', 100_000_000_000), 'stock' => $stock];
        }
        if ($variants === []) {
            $variants[] = ['sku' => null, 'options' => [], 'price_minor' => null, 'stock' => null];
        }

        return ['title' => $title, 'description' => $desc, 'price_minor' => $price, 'sale_price_minor' => $sale, 'sku' => $sku,
            'category' => isset($p['category']) && $p['category'] !== '' ? mb_substr((string) $p['category'], 0, 120) : null, 'tags' => array_slice($tags, 0, 30), 'images' => array_slice($images, 0, 10), 'variants' => $variants];
    }

    private static function int(mixed $v, string $name, int $max): ?int
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (! is_int($v) && ! (is_string($v) && preg_match('/\A\d{1,15}\z/', $v) === 1)) {
            throw new \InvalidArgumentException("$name must be a whole number");
        }
        $n = (int) $v;
        if ($n < 0 || $n > $max) {
            throw new \InvalidArgumentException("$name out of range");
        }

        return $n;
    }
}
