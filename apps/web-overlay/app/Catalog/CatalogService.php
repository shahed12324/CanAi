<?php

declare(strict_types=1);

namespace App\Catalog;

use App\Proposals\Money;

final class CatalogService
{
    public function __construct(private readonly CatalogRepo $repo) {}

    /**
     * Turns what the form sent into clean fields. Prices are typed in taka ("1,250.50"); everything is length-checked here
     * and again by the database CHECKs.
     *
     * @param  array<string, mixed>  $in
     * @return array{fields: array<string, mixed>, errors: array<string, string>}
     */
    public static function normalize(array $in): array
    {
        $e = [];
        $title = trim((string) ($in['title'] ?? ''));
        if ($title === '' || mb_strlen($title) > 250) {
            $e['title'] = 'Write a title (within 250 characters).';
        }
        $desc = trim((string) ($in['description'] ?? ''));
        if (mb_strlen($desc) > 20000) {
            $e['description'] = 'The description is too long.';
        }
        $price = 0;
        if (trim((string) ($in['price'] ?? '')) !== '') {
            $p = Money::parse((string) $in['price']);
            if ($p === null) {
                $e['price'] = 'The price could not be understood.';
            } else {
                $price = $p;
            }
        }
        $sale = null;
        if (trim((string) ($in['sale_price'] ?? '')) !== '') {
            $s = Money::parse((string) $in['sale_price']);
            if ($s === null || $s >= $price) {
                $e['sale_price'] = 'The sale price must be lower than the price.';
            } else {
                $sale = $s;
            }
        }
        $cur = strtoupper(trim((string) ($in['currency'] ?? 'BDT')) ?: 'BDT');
        if (preg_match('/\A[A-Z]{3}\z/', $cur) !== 1) {
            $e['currency'] = 'The currency code is 3 letters (BDT).';
        }
        $sku = trim((string) ($in['sku'] ?? ''));
        if (mb_strlen($sku) > 80) {
            $e['sku'] = 'SKU Within 80 characters.';
        }
        $cat = trim((string) ($in['category'] ?? ''));
        if (mb_strlen($cat) > 120) {
            $e['category'] = 'The category is within 120 characters.';
        }
        $tags = array_slice(array_values(array_unique(array_filter(array_map(fn ($t) => mb_substr(trim($t), 0, 40), preg_split('/[,\n|]/u', (string) ($in['tags'] ?? '')) ?: []), fn ($t) => $t !== ''))), 0, 30);
        $images = [];
        foreach (preg_split('/\R/u', (string) ($in['images'] ?? '')) ?: [] as $u) {
            $u = trim($u);
            if ($u === '') {
                continue;
            }
            if (preg_match('#\Ahttps?://[^\s]{4,500}\z#i', $u) !== 1) {
                $e['images'] = "The image link is not valid: $u";
                break;
            }
            $images[] = $u;
        }
        if (count($images) > 10) {
            $e['images'] = 'At most 10 images.';
        }
        $seo = [];
        foreach (['seo_title' => 70, 'seo_description' => 180] as $k => $max) {
            $v = trim((string) ($in[$k] ?? ''));
            if (mb_strlen($v) > $max) {
                $e[$k] = "max $max characters.";
            } elseif ($v !== '') {
                $seo[$k] = $v;
            }
        }

        return ['errors' => $e, 'fields' => [
            'title' => $title, 'description' => $desc, 'price_minor' => $price, 'sale_price_minor' => $sale, 'currency' => $cur,
            'sku' => $sku === '' ? null : $sku, 'category' => $cat === '' ? null : $cat, 'tags' => $tags, 'images' => $images, 'seo' => $seo,
            'ai_generated' => ! empty($in['ai_generated']),
        ]];
    }

    /** @param array<string, mixed> $in @return array{id?: string, errors?: array<string, string>} */
    public function create(string $tenantId, string $userId, array $in): array
    {
        $n = self::normalize($in);
        if ($n['errors'] !== []) {
            return ['errors' => $n['errors']];
        }
        $store = $this->repo->ensureStore($tenantId);
        if ($n['fields']['sku'] !== null && $this->repo->idBySku($store, $n['fields']['sku']) !== null) {
            return ['errors' => ['sku' => 'This SKU already exists.']];
        }
        $id = $this->repo->create($tenantId, $userId, $store, $n['fields']);
        $this->repo->addVariant($tenantId, $id, null, [], null, true);

        return ['id' => $id];
    }

    /** @param array<string, mixed> $in @return array{ok?: true, errors?: array<string, string>} */
    public function update(string $id, array $in): array
    {
        $p = $this->repo->find($id);
        if ($p === null) {
            return ['errors' => ['title' => 'The product was not found.']];
        }
        if ($p['status'] === 'archived') {
            return ['errors' => ['title' => 'An archived product cannot be edited — restore it first.']];
        }
        $n = self::normalize($in);
        if ($n['errors'] !== []) {
            return ['errors' => $n['errors']];
        }
        $other = $n['fields']['sku'] === null ? null : $this->repo->idBySku((string) $p['store_id'], $n['fields']['sku']);
        if ($other !== null && $other !== $id) {
            return ['errors' => ['sku' => 'This SKU already exists.']];
        }
        $this->repo->update($id, $n['fields']);

        return ['ok' => true];
    }

    /**
     * Applies a parsed CSV. A bad row never stops the others; one product failing leaves the rest done.
     *
     * @return array{total: int, created: int, updated: int, failed: int, errors: list<array{row: int, message: string}>}
     */
    public function import(string $tenantId, string $userId, string $filename, string $csv): array
    {
        $parsed = CatalogCsv::parse($csv);
        $errors = $parsed['errors'];
        $created = 0;
        $updated = 0;
        $store = $parsed['products'] === [] ? '' : $this->repo->ensureStore($tenantId);
        foreach ($parsed['products'] as $p) {
            try {
                $id = $p['sku'] === null ? null : $this->repo->idBySku($store, $p['sku']);
                if ($id === null) {
                    $id = $this->repo->create($tenantId, $userId, $store, [
                        'title' => $p['title'], 'description' => $p['description'], 'price_minor' => $p['price_minor'] ?? 0, 'sale_price_minor' => $p['sale_price_minor'],
                        'currency' => $p['currency'], 'sku' => $p['sku'], 'category' => $p['category'], 'tags' => $p['tags'], 'images' => $p['images'], 'seo' => [],
                    ]);
                    if ($p['variants'] === []) {
                        $v = $this->repo->addVariant($tenantId, $id, null, [], null, true);
                        $this->setStock($v, $p['stock'], $userId);
                    }
                    $created++;
                } else {
                    $cur = $this->repo->find($id);
                    $this->repo->update($id, [
                        'title' => $p['title'], 'description' => $p['description'] !== '' ? $p['description'] : (string) $cur['description'], 'price_minor' => $p['price_minor'] ?? (int) $cur['price_minor'],
                        'sale_price_minor' => $p['sale_price_minor'], 'currency' => $p['currency'], 'sku' => $p['sku'], 'category' => $p['category'] ?? $cur['category'],
                        'tags' => $p['tags'] !== [] ? $p['tags'] : $cur['tags'], 'images' => $p['images'] !== [] ? $p['images'] : $cur['images'], 'seo' => $cur['seo'], 'ai_generated' => false,
                    ]);
                    if ($p['variants'] === [] && $p['stock'] !== null && count($cur['variants']) === 1 && $cur['variants'][0]['options'] === []) {
                        $this->setStock($cur['variants'][0]['id'], $p['stock'], $userId);
                    }
                    $updated++;
                }
                foreach ($p['variants'] as $v) {
                    $vid = $this->repo->variantByOptions($id, $v['options']) ?? $this->repo->addVariant($tenantId, $id, $v['sku'], $v['options'], $v['price_minor'], true);
                    $this->setStock($vid, $v['stock'], $userId);
                }
            } catch (\Throwable $e) {
                $errors[] = ['row' => (int) $p['row'], 'message' => self::friendly($e)];
                if (function_exists('report')) {
                    report($e);
                }
            }
        }
        $total = $parsed['rows'];
        $failed = count($errors);
        $this->repo->recordImport($tenantId, $userId, $filename, $total, $created, $updated, $failed, $errors);

        return ['total' => $total, 'created' => $created, 'updated' => $updated, 'failed' => $failed, 'errors' => array_slice($errors, 0, 100)];
    }

    /** Moves a variant's stock to an exact quantity (ledger row 'import'). */
    private function setStock(string $variantId, ?int $target, string $userId): void
    {
        if ($target === null) {
            return;
        }
        $v = $this->variantQty($variantId);
        $delta = $target - $v;
        if ($delta !== 0) {
            $this->repo->stock($variantId, $delta, 'import', null, $userId);
        }
    }

    private function variantQty(string $variantId): int
    {
        $last = $this->repo->movements($variantId, 1);

        return $last === [] ? 0 : (int) $last[0]['qty_after'];
    }

    /** @return array{ok: bool, qty?: int, error?: string} */
    public function adjustStock(string $variantId, int $delta, string $reason, ?string $note, string $userId): array
    {
        if ($delta === 0 || abs($delta) > 1_000_000) {
            return ['ok' => false, 'error' => 'The change amount is not valid.'];
        }
        if (! in_array($reason, ['adjust', 'sale', 'return', 'correction'], true)) {
            return ['ok' => false, 'error' => 'The reason is not valid.'];
        }
        try {
            return ['ok' => true, 'qty' => $this->repo->stock($variantId, $delta, $reason, $note, $userId)];
        } catch (\RuntimeException $e) {
            return ['ok' => false, 'error' => self::friendly($e)];
        }
    }

    public static function friendly(\Throwable $e): string
    {
        return match (true) {
            $e->getMessage() === 'insufficient_stock' => 'There is not that much in stock — the quantity cannot be negative.',
            $e->getMessage() === 'not_tracked' => 'Stock is not tracked for this variant.',
            $e->getMessage() === 'variant_archived' => 'The variant is archived.',
            str_contains($e->getMessage(), 'products_sku_per_store') || str_contains($e->getMessage(), 'product_variants_sku') => 'SKU Already exists.',
            str_contains($e->getMessage(), 'product_variants_options') => 'A variant with the same option already exists.',
            default => 'This row could not be saved.',
        };
    }

    public function submit(string $id, string $userId): string
    {
        return $this->repo->submit($id, $userId);
    }

    public function decide(string $id, string $userId, string $decision, ?string $note): string
    {
        return $this->repo->decide($id, $userId, $decision, $note);
    }

    public function publish(string $id, string $userId): string
    {
        return $this->repo->publish($id, $userId);
    }

    /** Bengali text for a workflow result code. */
    public static function message(string $result): string
    {
        return match (true) {
            $result === 'submitted' => 'Sent for approval.',
            $result === 'approved' => 'Approved. Now publish.',
            $result === 'rejected' => 'Returned.',
            $result === 'published' => 'Published.',
            $result === 'archived' => 'Archived.',
            $result === 'restored' => 'Returned to draft — approval is needed again.',
            $result === 'emergency' => 'The emergency stop is on — approval/publishing is off now.',
            $result === 'not_draft' => 'This is not in draft state now.',
            $result === 'not_pending' => 'This is no longer awaiting approval.',
            $result === 'not_approved' => 'Approval is needed first.',
            $result === 'incomplete:description' => 'Write a description, then send.',
            $result === 'incomplete:price' => 'Give a price, then send.',
            $result === 'incomplete:variant' => 'At least one active variant is needed.',
            default => 'The action could not be done.',
        };
    }
}
