<?php

declare(strict_types=1);

namespace App\Catalog;

use App\Proposals\Money;

/**
 * Bulk-import CSV -> products with variants. Pure (no database): the service applies the result.
 *
 * Columns (header row required, any order, case-insensitive):
 *   sku, title, description, price, sale_price, currency, category, tags (a|b), images (url|url), stock, options ("Size=M;Color=Red"), parent_sku
 * A row WITH parent_sku is a variant of the product whose sku is parent_sku; its sku/price/stock/options describe the variant.
 * A row WITHOUT it is a product. Prices are in taka (or the currency's major unit), e.g. 1,250.50.
 */
final class CatalogCsv
{
    public const MAX_ROWS = 2000;

    public const MAX_BYTES = 2_097_152;

    private const KNOWN = ['sku', 'title', 'description', 'price', 'sale_price', 'currency', 'category', 'tags', 'images', 'stock', 'options', 'parent_sku'];

    /**
     * @return array{products: list<array<string, mixed>>, errors: list<array{row: int, message: string}>, rows: int}
     */
    public static function parse(string $csv): array
    {
        $out = ['products' => [], 'errors' => [], 'rows' => 0];
        if (strlen($csv) > self::MAX_BYTES) {
            $out['errors'][] = ['row' => 0, 'message' => 'The file is over 2 MB.'];

            return $out;
        }
        $csv = preg_replace('/\A\xEF\xBB\xBF/', '', $csv) ?? $csv;
        $csv = str_replace(["\r\n", "\r"], "\n", $csv);
        $firstLine = strstr($csv, "\n", true);
        $first = $firstLine === false ? $csv : $firstLine;
        $delim = substr_count($first, ';') > substr_count($first, ',') ? ';' : ',';

        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $csv);
        rewind($stream);
        $header = fgetcsv($stream, 0, $delim, '"', '');
        if ($header === false || $header === [null]) {
            $out['errors'][] = ['row' => 0, 'message' => 'The file is empty.'];

            return $out;
        }
        $cols = [];
        foreach ($header as $i => $h) {
            $k = strtolower(trim((string) $h));
            if (in_array($k, self::KNOWN, true)) {
                $cols[$k] = $i;
            }
        }
        foreach (['title'] as $need) {
            if (! isset($cols[$need]) && ! isset($cols['parent_sku'])) {
                $out['errors'][] = ['row' => 1, 'message' => "'$need' No column."];

                return $out;
            }
        }

        $bySku = [];
        $rowNo = 1;
        while (($r = fgetcsv($stream, 0, $delim, '"', '')) !== false) {
            $rowNo++;
            if ($r === [null] || implode('', array_map(fn ($x) => trim((string) $x), $r)) === '') {
                continue;
            }
            if (++$out['rows'] > self::MAX_ROWS) {
                $out['errors'][] = ['row' => $rowNo, 'message' => 'max '.self::MAX_ROWS.' row.'];
                break;
            }
            $get = fn (string $k): string => isset($cols[$k]) ? trim((string) ($r[$cols[$k]] ?? '')) : '';
            $parent = $get('parent_sku');

            $price = null;
            if ($get('price') !== '') {
                $price = Money::parse($get('price'));
                if ($price === null) {
                    $out['errors'][] = ['row' => $rowNo, 'message' => "The price could not be understood: '{$get('price')}'"];

                    continue;
                }
            }
            $stock = null;
            if ($get('stock') !== '') {
                if (preg_match('/\A\d{1,7}\z/', $get('stock')) !== 1) {
                    $out['errors'][] = ['row' => $rowNo, 'message' => "Stock must be a number: '{$get('stock')}'"];

                    continue;
                }
                $stock = (int) $get('stock');
            }
            $options = self::options($get('options'));
            if ($options === null) {
                $out['errors'][] = ['row' => $rowNo, 'message' => "The option is wrong (Size=M;Color=Red): '{$get('options')}'"];

                continue;
            }

            if ($parent !== '') {
                if ($options === []) {
                    $out['errors'][] = ['row' => $rowNo, 'message' => 'A variant must have options.'];

                    continue;
                }
                $bySku[strtolower($parent)]['variants'][] = ['options' => $options, 'sku' => $get('sku') ?: null, 'price_minor' => $price, 'stock' => $stock, 'row' => $rowNo];
                $bySku[strtolower($parent)]['parent_row'] ??= $rowNo;

                continue;
            }

            $title = $get('title');
            if ($title === '' || mb_strlen($title) > 250) {
                $out['errors'][] = ['row' => $rowNo, 'message' => 'The title is empty or over 250 characters.'];

                continue;
            }
            $sale = null;
            if ($get('sale_price') !== '') {
                $sale = Money::parse($get('sale_price'));
                if ($sale === null || ($price !== null && $sale >= $price)) {
                    $out['errors'][] = ['row' => $rowNo, 'message' => 'The sale price must be lower than the price.'];

                    continue;
                }
            }
            $cur = strtoupper($get('currency') ?: 'BDT');
            if (preg_match('/\A[A-Z]{3}\z/', $cur) !== 1) {
                $out['errors'][] = ['row' => $rowNo, 'message' => "The currency is wrong: '$cur'"];

                continue;
            }
            $images = self::list($get('images'));
            foreach ($images as $u) {
                if (preg_match('#\Ahttps?://[^\s]{4,500}\z#i', $u) !== 1) {
                    $out['errors'][] = ['row' => $rowNo, 'message' => "The image link is wrong: '$u'"];

                    continue 2;
                }
            }
            $key = $get('sku') !== '' ? strtolower($get('sku')) : 'row-'.$rowNo;
            if (isset($bySku[$key]['title'])) {
                $out['errors'][] = ['row' => $rowNo, 'message' => "SKU Twice: '{$get('sku')}'"];

                continue;
            }
            $bySku[$key] = ($bySku[$key] ?? ['variants' => []]) + [];
            $bySku[$key] = array_merge($bySku[$key], [
                'sku' => $get('sku') ?: null, 'title' => $title, 'description' => $get('description'), 'price_minor' => $price,
                'sale_price_minor' => $sale, 'currency' => $cur, 'category' => $get('category') ?: null,
                'tags' => array_slice(self::list($get('tags')), 0, 30), 'images' => array_slice($images, 0, 10), 'stock' => $stock, 'row' => $rowNo,
            ]);
        }
        fclose($stream);

        foreach ($bySku as $k => $p) {
            if (! isset($p['title'])) {
                $out['errors'][] = ['row' => $p['parent_row'] ?? 0, 'message' => "parent_sku '$k' has no product row."];

                continue;
            }
            $seen = [];
            foreach ($p['variants'] as $v) {
                $sig = json_encode($v['options'], JSON_UNESCAPED_UNICODE);
                if (isset($seen[$sig])) {
                    $out['errors'][] = ['row' => $v['row'], 'message' => 'A variant with the same option appears twice.'];

                    continue 2;
                }
                $seen[$sig] = true;
            }
            $out['products'][] = $p;
        }

        return $out;
    }

    /** @return list<string> */
    public static function list(string $v): array
    {
        return array_values(array_filter(array_map('trim', explode('|', $v)), fn ($x) => $x !== ''));
    }

    /** @return array<string, string>|null  null = malformed; [] = none */
    public static function options(string $v): ?array
    {
        if (trim($v) === '') {
            return [];
        }
        $o = [];
        foreach (explode(';', $v) as $pair) {
            if (trim($pair) === '') {
                continue;
            }
            $kv = explode('=', $pair, 2);
            if (count($kv) !== 2 || trim($kv[0]) === '' || trim($kv[1]) === '' || mb_strlen($kv[0]) > 40 || mb_strlen($kv[1]) > 80) {
                return null;
            }
            $o[trim($kv[0])] = trim($kv[1]);
        }
        ksort($o);

        return count($o) > 3 ? null : $o;
    }
}
