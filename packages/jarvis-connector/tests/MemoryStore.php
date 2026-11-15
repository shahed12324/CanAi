<?php

declare(strict_types=1);

namespace Jarvis\Connector\Tests;

use Jarvis\Connector\ProductStore;

final class MemoryStore implements ProductStore
{
    public array $products = [];

    public array $stock = [];

    public int $n = 0;

    public ?\Throwable $fail = null;

    public function page(?string $cursor, int $limit): array
    {
        if ($this->fail) {
            throw $this->fail;
        }
        $ids = array_map('strval', array_keys($this->products));
        $start = $cursor === null ? 0 : (int) array_search($cursor, $ids, true) + 1;
        $slice = array_slice($ids, $start, $limit);

        return ['items' => array_map(fn ($i) => $this->products[$i], $slice), 'next' => $start + $limit < count($ids) ? (string) end($slice) : null];
    }

    public function find(string $id): ?array
    {
        return $this->products[$id] ?? null;
    }

    public function upsert(?string $id, array $product): string
    {
        if ($this->fail) {
            throw $this->fail;
        }
        if ($id !== null && ! isset($this->products[$id])) {
            throw new \OutOfBoundsException('nf');
        }
        $id ??= (string) (++$this->n);
        $this->products[$id] = ['external_id' => $id] + $product;

        return $id;
    }

    public function setStock(string $variantExternalId, int $qty): void
    {
        if (str_starts_with($variantExternalId, 'zz')) {
            throw new \OutOfBoundsException('nf');
        }
        $this->stock[$variantExternalId] = $qty;
    }
}
