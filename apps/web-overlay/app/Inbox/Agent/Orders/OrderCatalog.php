<?php

declare(strict_types=1);

namespace App\Inbox\Agent\Orders;

use App\Inbox\Agent\Knowledge;

/** What the order flow may sell: variants of products with an approved live copy, priced exactly as order_create() will price them. */
final class OrderCatalog
{
    /**
     * @param  list<array<string, mixed>>  $products  from AgentRepo (id, live, in_stock)
     * @return array<string, array{product: string, label: string, unit: int, currency: string, in_stock: bool, ref: string}>  variant id => facts
     */
    public static function index(array $products): array
    {
        $out = [];
        foreach ($products as $i => $p) {
            $l = is_array($p['live'] ?? null) ? $p['live'] : [];
            $stock = is_array($p['in_stock'] ?? null) ? $p['in_stock'] : [];
            foreach (is_array($l['variants'] ?? null) ? $l['variants'] : [] as $v) {
                $vid = is_array($v) ? (string) ($v['id'] ?? '') : '';
                if (preg_match('/\A[0-9A-Za-z]{26}\z/', $vid) !== 1) {
                    continue;
                }
                $base = isset($v['price_minor']) && $v['price_minor'] !== null ? (int) $v['price_minor'] : (int) ($l['price_minor'] ?? 0);
                $sale = isset($l['sale_price_minor']) && $l['sale_price_minor'] !== null ? (int) $l['sale_price_minor'] : null;
                $unit = (! isset($v['price_minor']) || $v['price_minor'] === null) && $sale !== null && $sale > 0 && $sale < $base ? $sale : $base;
                $opts = is_array($v['options'] ?? null) ? $v['options'] : [];
                ksort($opts);
                $label = trim(self::clean((string) ($l['title'] ?? '')).($opts === [] ? '' : ' ('.implode(', ', array_map(fn ($k, $x) => self::clean((string) $k).': '.self::clean((string) $x), array_keys($opts), $opts)).')'));
                $out[$vid] = ['product' => (string) ($p['id'] ?? ''), 'label' => $label, 'unit' => $unit, 'currency' => (string) ($l['currency'] ?? 'BDT'), 'in_stock' => (bool) ($stock[$vid] ?? false), 'ref' => 'P'.($i + 1)];
            }
        }

        return $out;
    }

    /** Model-facing list with variant ids. @param array<string, array<string, mixed>> $index */
    public static function render(array $index): string
    {
        $lines = [];
        foreach ($index as $vid => $f) {
            $lines[] = sprintf('%s | variant_id=%s | %s | price %s %s | %s', $f['ref'], $vid, $f['label'], Knowledge::money($f['unit']), $f['currency'], $f['in_stock'] ? 'in stock' : 'OUT OF STOCK');
        }

        return implode("\n", $lines);
    }

    private static function clean(string $s): string
    {
        return trim(preg_replace('/\s+/u', ' ', str_replace(['[', ']', '<', '>', '`', '|'], ' ', $s)) ?? '');
    }
}
