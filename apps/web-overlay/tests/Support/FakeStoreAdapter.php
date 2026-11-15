<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Catalog\Stores\BaseAdapter;
use App\Catalog\Stores\StoreAdapter;
use App\Catalog\Stores\StoreException;

/** A pretend store: `remote` is what it holds (by external id); pushes edit it the way a real store would. */
final class FakeStoreAdapter implements StoreAdapter
{
    /** @var array<string, array<string, mixed>> */
    public array $remote = [];

    public array $pushes = [];

    public array $stockPushes = [];

    public ?StoreException $failPage = null;

    public ?StoreException $failPush = null;

    public int $pageSize = 100;

    public function ping(): string
    {
        return 'Fake';
    }

    public function page(?string $cursor): array
    {
        if ($this->failPage !== null) {
            throw $this->failPage;
        }
        $all = array_values($this->remote);
        $off = (int) $cursor;
        $items = array_slice($all, $off, $this->pageSize);

        return ['items' => $items, 'next' => $off + $this->pageSize < count($all) ? (string) ($off + $this->pageSize) : null];
    }

    public function fetch(string $externalId): ?array
    {
        return $this->remote[$externalId] ?? null;
    }

    public function push(array $product, ?string $externalId): string
    {
        if ($this->failPush !== null) {
            throw $this->failPush;
        }
        $this->pushes[] = [$product, $externalId];
        $id = $externalId ?? 'R'.(count($this->remote) + 1);
        $variants = [];
        foreach ($product['variants'] as $i => $v) {
            $variants[] = ['external_id' => $id.'-'.($i + 1), 'sku' => $v['sku'], 'options' => $v['options'], 'price_minor' => $v['price_minor'], 'stock' => $v['stock']];
        }
        $this->remote[$id] = ['external_id' => $id, 'title' => $product['title'], 'description' => $product['description'], 'price_minor' => $product['price_minor'], 'sale_price_minor' => $product['sale_price_minor'],
            'currency' => $product['currency'], 'sku' => $product['sku'], 'category' => $product['category'], 'tags' => $product['tags'], 'images' => $product['images'], 'variants' => $variants];

        return $id;
    }

    public function pushStock(string $variantExternalId, int $qty): void
    {
        $this->stockPushes[] = [$variantExternalId, $qty];
    }

    public static function sig(array $options): string
    {
        return BaseAdapter::sig($options);
    }

    /** @return array<string, mixed> */
    public static function item(string $id, string $title = 'Tee', array $variants = [], array $over = []): array
    {
        return $over + ['external_id' => $id, 'title' => $title, 'description' => 'Soft', 'price_minor' => 50000, 'sale_price_minor' => null, 'currency' => 'BDT', 'sku' => null, 'category' => null, 'tags' => [], 'images' => [],
            'variants' => $variants ?: [['external_id' => $id, 'sku' => null, 'options' => [], 'price_minor' => null, 'stock' => 5]]];
    }
}
