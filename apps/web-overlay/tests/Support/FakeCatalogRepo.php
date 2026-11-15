<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Catalog\CatalogRepo;

class FakeCatalogRepo implements CatalogRepo
{
    /** @var array<string, array<string, mixed>> */
    public array $products = [];

    /** @var array<string, array<string, mixed>> */
    public array $variants = [];

    /** @var list<array<string, mixed>> */
    public array $moves = [];

    public array $imports = [];

    private int $n = 0;

    public function ensureStore(string $tenantId): string
    {
        return 'STORE';
    }

    public function list(?string $status, ?string $search, int $limit): array
    {
        return array_values($this->products);
    }

    public function find(string $id): ?array
    {
        if (! isset($this->products[$id])) {
            return null;
        }

        return $this->products[$id] + ['variants' => array_values(array_filter($this->variants, fn ($v) => $v['product_id'] === $id && ! $v['archived']))];
    }

    public function create(string $tenantId, ?string $userId, string $storeId, array $f): string
    {
        $id = 'P'.++$this->n;
        $this->products[$id] = $f + ['id' => $id, 'store_id' => $storeId, 'status' => 'draft', 'description' => '', 'sale_price_minor' => null, 'category' => null, 'seo' => [], 'tags' => [], 'images' => [], 'live' => null, 'external_id' => null];

        return $id;
    }

    public function update(string $id, array $f): void
    {
        $this->products[$id] = $f + $this->products[$id];
    }

    public function idBySku(string $storeId, string $sku): ?string
    {
        foreach ($this->products as $id => $p) {
            if (isset($p['sku']) && strtolower((string) $p['sku']) === strtolower($sku)) {
                return $id;
            }
        }

        return null;
    }

    public function addVariant(string $tenantId, string $productId, ?string $sku, array $options, ?int $priceMinor, bool $track): string
    {
        $id = 'V'.++$this->n;
        $this->variants[$id] = ['id' => $id, 'product_id' => $productId, 'sku' => $sku, 'options' => $options, 'price_minor' => $priceMinor, 'stock_qty' => 0, 'track_stock' => $track, 'archived' => false];

        return $id;
    }

    public function updateVariant(string $id, array $f): void
    {
        $this->variants[$id] = $f + $this->variants[$id];
    }

    public function archiveVariant(string $id): void
    {
        $this->variants[$id]['archived'] = true;
    }

    public function variantByOptions(string $productId, array $options): ?string
    {
        foreach ($this->variants as $id => $v) {
            if ($v['product_id'] === $productId && ! $v['archived'] && $v['options'] === $options) {
                return $id;
            }
        }

        return null;
    }

    public function softDelete(string $id): void
    {
        unset($this->products[$id]);
    }

    public function stock(string $variantId, int $delta, string $reason, ?string $note, ?string $userId): int
    {
        $v = &$this->variants[$variantId];
        if (! $v['track_stock']) {
            throw new \RuntimeException('not_tracked');
        }
        if ($v['stock_qty'] + $delta < 0) {
            throw new \RuntimeException('insufficient_stock');
        }
        $v['stock_qty'] += $delta;
        array_unshift($this->moves, ['variant_id' => $variantId, 'delta' => $delta, 'qty_after' => $v['stock_qty'], 'reason' => $reason]);

        return $v['stock_qty'];
    }

    public function submit(string $id, string $userId): string
    {
        return 'submitted';
    }

    public function decide(string $id, string $userId, string $decision, ?string $note): string
    {
        return $decision === 'approve' ? 'approved' : 'rejected';
    }

    public function publish(string $id, string $userId): string
    {
        return 'published';
    }

    public function archive(string $id, string $userId): string
    {
        return 'archived';
    }

    public function restore(string $id, string $userId): string
    {
        return 'restored';
    }

    public function events(string $id): array
    {
        return [];
    }

    public function movements(string $variantId, int $limit): array
    {
        return array_values(array_filter($this->moves, fn ($m) => $m['variant_id'] === $variantId));
    }

    public function recordImport(string $tenantId, string $userId, string $filename, int $total, int $created, int $updated, int $failed, array $errors): void
    {
        $this->imports[] = compact('filename', 'total', 'created', 'updated', 'failed', 'errors');
    }

    public function imports(int $limit): array
    {
        return $this->imports;
    }
}
