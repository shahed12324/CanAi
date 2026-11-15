<?php

declare(strict_types=1);

namespace App\Inbox\Agent;

/**
 * Turns published products into the only facts the agent may use. Prices come from the live (approved) copy, formatted by us — the
 * model is told to quote them exactly and the ReplyGuard checks it did.
 */
final class Knowledge
{
    /**
     * @param  list<array<string, mixed>>  $products  from AgentRepo::search(): live (array), in_stock (variant id => bool), id
     * @return array{text: string, prices: list<int>}
     */
    public static function render(array $products): array
    {
        $lines = [];
        $prices = [];
        foreach ($products as $i => $p) {
            $l = is_array($p['live'] ?? null) ? $p['live'] : [];
            $cur = (string) ($l['currency'] ?? 'BDT');
            $price = (int) ($l['price_minor'] ?? 0);
            $sale = isset($l['sale_price_minor']) && $l['sale_price_minor'] !== null ? (int) $l['sale_price_minor'] : null;
            $prices[] = $price;
            $row = sprintf('[P%d] %s', $i + 1, self::clean((string) ($l['title'] ?? '')));
            if (! empty($l['category'])) {
                $row .= ' | category: '.self::clean((string) $l['category']);
            }
            $row .= ' | price: '.self::money($price).' '.$cur;
            if ($sale !== null && $sale > 0 && $sale < $price) {
                $prices[] = $sale;
                $row .= ' | sale price now: '.self::money($sale).' '.$cur;
            }
            $stock = is_array($p['in_stock'] ?? null) ? $p['in_stock'] : [];
            $vs = [];
            foreach (is_array($l['variants'] ?? null) ? $l['variants'] : [] as $v) {
                if (! is_array($v)) {
                    continue;
                }
                $opts = is_array($v['options'] ?? null) ? $v['options'] : [];
                $label = $opts === [] ? '' : implode(', ', array_map(fn ($k, $x) => self::clean((string) $k).'='.self::clean((string) $x), array_keys($opts), $opts));
                $vp = isset($v['price_minor']) && $v['price_minor'] !== null ? (int) $v['price_minor'] : null;
                if ($vp !== null) {
                    $prices[] = $vp;
                }
                $has = (bool) ($stock[$v['id'] ?? ''] ?? false);
                $vs[] = trim(($label !== '' ? $label : 'standard').($vp !== null && $vp !== $price ? ' price '.self::money($vp).' '.$cur : '').' - '.($has ? 'in stock' : 'OUT OF STOCK'));
            }
            if ($vs !== []) {
                $row .= ' | variants: '.implode('; ', array_slice($vs, 0, 12));
            }
            $desc = self::clean((string) ($l['description'] ?? ''));
            if ($desc !== '') {
                $row .= ' | about: '.mb_substr($desc, 0, 400);
            }
            $lines[] = $row;
        }

        return ['text' => implode("\n", $lines), 'prices' => array_values(array_unique($prices))];
    }

    public static function money(int $minor): string
    {
        return number_format($minor / 100, $minor % 100 === 0 ? 0 : 2, '.', '');
    }

    /** One line, no markers that could look like our own structure. */
    private static function clean(string $s): string
    {
        return trim(preg_replace('/\s+/u', ' ', str_replace(['[', ']', '<', '>', '`'], ' ', $s)) ?? '');
    }

    /** Keywords for the product search: letters/digits, no filler words, at most 6. @return list<string> */
    public static function keywords(string $text): array
    {
        $stop = array_flip(['the', 'and', 'for', 'you', 'are', 'is', 'it', 'this', 'that', 'what', 'how', 'much', 'price', 'cost', 'have', 'has', 'any', 'can', 'please', 'plz', 'pls', 'available', 'stock', 'dam', 'koto', 'ache', 'achhe', 'kothay', 'ki', 'kon', 'ta', 'tar', 'er', 'eta', 'ota', 'bhai', 'vai', 'apu', 'apa', 'sir', 'madam', 'hello', 'hi', 'salam', 'assalamu', 'alaikum', 'kemon', 'kemne', 'janaben', 'jante', 'chai', 'korun', 'dite', 'pabo', 'paben', 'কত', 'দাম', 'আছে', 'কি', 'কী', 'এর', 'এটা', 'ওটা', 'এটার', 'ভাই', 'আপু', 'আপা', 'স্যার', 'দিন', 'জানাবেন', 'পাব', 'পাবো', 'চাই', 'করুন', 'আসসালামু', 'আলাইকুম', 'কিভাবে', 'কোথায়', 'কোন']);
        preg_match_all('/[\p{L}\p{N}]+/u', mb_strtolower(ReplyGuard::latin($text)), $m);
        $out = [];
        foreach ($m[0] as $w) {
            if (mb_strlen($w) >= 3 && ! isset($stop[$w]) && ! ctype_digit($w)) {
                $out[$w] = true;
            }
        }

        return array_slice(array_keys($out), 0, 6);
    }
}
