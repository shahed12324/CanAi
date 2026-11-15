<?php

declare(strict_types=1);

namespace App\Proposals;

/** Turns a stored proposal + brand kit + chosen portfolio items into the exact payload the engine renders. */
final class DocumentBuilder
{
    private const LABELS = [
        'bn' => ['prepared_for' => 'প্রস্তুত করা হয়েছে', 'date' => 'তারিখ', 'item' => 'বিবরণ', 'qty' => 'পরিমাণ', 'unit_price' => 'একক মূল্য', 'amount' => 'মোট', 'pricing' => 'মূল্য', 'portfolio' => 'আমাদের কাজ', 'contact' => 'যোগাযোগ',
            'subtotal' => 'সাবটোটাল', 'discount' => 'ছাড়', 'vat' => 'ভ্যাট', 'total' => 'সর্বমোট'],
        'en' => ['prepared_for' => 'Prepared for', 'date' => 'Date', 'item' => 'Item', 'qty' => 'Qty', 'unit_price' => 'Unit price', 'amount' => 'Amount', 'pricing' => 'Pricing', 'portfolio' => 'Selected work', 'contact' => 'Contact',
            'subtotal' => 'Subtotal', 'discount' => 'Discount', 'vat' => 'VAT', 'total' => 'Total'],
    ];

    /**
     * @param  array{title: string, language: string, client_name?: string, sections?: array, pricing?: array, portfolio?: list<array<string, mixed>>, brand?: array<string, mixed>|null, brand_fallback_name?: string, date: \DateTimeInterface}  $in
     * @return array<string, mixed>
     */
    public static function build(array $in): array
    {
        $lang = $in['language'] === 'en' ? 'en' : 'bn';
        $L = self::LABELS[$lang];
        $brand = $in['brand'] ?? null;
        $doc = [
            'language' => $lang,
            'title' => $in['title'],
            'client_name' => (string) ($in['client_name'] ?? ''),
            'date_text' => DocDate::format($in['date'], $lang),
            'sections' => array_values(array_map(
                fn ($s) => ['heading' => (string) ($s['heading'] ?? ''), 'body' => (string) ($s['body'] ?? '')],
                array_filter((array) ($in['sections'] ?? []), 'is_array'),
            )),
            'brand' => [
                'name' => (string) ($brand['name'] ?? $in['brand_fallback_name'] ?? ''),
                'primary' => (string) ($brand['primary_color'] ?? '#1f3a5f'),
                'accent' => (string) ($brand['accent_color'] ?? '#e07a1f'),
                'logo_b64' => ($brand['logo_b64'] ?? null) ?: null,
                'email' => (string) ($brand['email'] ?? ''),
                'phone' => (string) ($brand['phone'] ?? ''),
                'website' => (string) ($brand['website'] ?? ''),
                'address' => (string) ($brand['address'] ?? ''),
            ],
            'labels' => array_intersect_key($L, array_flip(['prepared_for', 'date', 'item', 'qty', 'unit_price', 'amount', 'pricing', 'portfolio', 'contact'])),
            'portfolio' => array_values(array_map(fn ($p) => ['title' => (string) $p['title'], 'url' => (string) ($p['url'] ?? ''), 'description' => (string) ($p['description'] ?? '')], $in['portfolio'] ?? [])),
        ];

        $pr = (array) ($in['pricing'] ?? []);
        $items = array_values(array_filter((array) ($pr['items'] ?? []), 'is_array'));
        if ($items !== []) {
            $discountBp = (int) ($pr['discount_bp'] ?? 0);
            $vatBp = (int) ($pr['vat_bp'] ?? 0);
            $c = Pricing::compute($items, $discountBp, $vatBp);
            $totals = [];
            if ($discountBp > 0 || $vatBp > 0) {
                $totals[] = ['label' => $L['subtotal'], 'value' => Money::format($c['subtotal'], $lang), 'strong' => false];
            }
            if ($discountBp > 0) {
                $totals[] = ['label' => $L['discount'].' ('.Pricing::formatPercent($discountBp, $lang).'%)', 'value' => '-'.Money::format($c['discount'], $lang), 'strong' => false];
            }
            if ($vatBp > 0) {
                $totals[] = ['label' => $L['vat'].' ('.Pricing::formatPercent($vatBp, $lang).'%)', 'value' => Money::format($c['vat'], $lang), 'strong' => false];
            }
            $totals[] = ['label' => $L['total'], 'value' => Money::format($c['total'], $lang), 'strong' => true];
            $doc['pricing'] = [
                'rows' => array_map(fn ($r) => ['name' => $r['name'], 'qty' => Money::formatNumber($r['qty'], $lang), 'unit_price' => Money::format($r['unit_minor'], $lang), 'total' => Money::format($r['total_minor'], $lang)], $c['rows']),
                'totals' => $totals,
                'note' => (string) ($pr['note'] ?? ''),
            ];
        }

        return $doc;
    }
}
