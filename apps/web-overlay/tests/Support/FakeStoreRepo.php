<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Catalog\Stores\StoreRepo;

/** Works on the same FakeCatalogRepo arrays the sync edits, like the real tables. */
final class FakeStoreRepo implements StoreRepo
{
    public array $store = ['id' => 'S1', 'tenant_id' => 'T', 'platform' => 'woocommerce', 'name' => 'Shop', 'base_url' => 'https://shop.example.com', 'currency' => 'BDT', 'status' => 'active', 'last_sync_at' => null, 'last_error' => null];

    public bool $emergency = false;

    public bool $running = false;

    public ?array $creds = ['cipher' => 'x', 'version' => 1];

    public array $runs = [];

    public array $created = [];

    public function __construct(public FakeCatalogRepo $catalog) {}

    public function store(string $id): ?array
    {
        return $id === $this->store['id'] ? $this->store : null;
    }

    public function stores(): array
    {
        return [$this->store];
    }

    public function createStore(string $tenantId, string $platform, string $name, string $baseUrl, string $cipher, int $keyVersion, string $currency): string
    {
        $this->created[] = compact('tenantId', 'platform', 'name', 'baseUrl', 'cipher', 'keyVersion', 'currency');

        return 'NEWSTORE';
    }

    public function updateStore(string $id, string $name, string $baseUrl, ?string $cipher, ?int $keyVersion, string $currency): void {}

    public function credentials(string $id): ?array
    {
        return $this->creds;
    }

    public function setStatus(string $id, string $status, ?string $lastError, bool $touchSync): void
    {
        $this->store['status'] = $status;
        $this->store['last_error'] = $lastError;
    }

    public function emergency(string $tenantId): bool
    {
        return $this->emergency;
    }

    public function reap(string $storeId): void {}

    public function beginRun(string $tenantId, string $storeId, string $trigger, ?string $userId): ?string
    {
        return $this->running ? null : 'RUN'.(count($this->runs) + 1);
    }

    public function finishRun(string $runId, string $status, array $c, ?string $note, array $errors): void
    {
        $this->runs[] = compact('runId', 'status', 'c', 'note', 'errors');
    }

    public function runs(string $storeId, int $limit): array
    {
        return $this->runs;
    }

    public function idByExternal(string $storeId, string $external): ?string
    {
        foreach ($this->catalog->products as $id => $p) {
            if (($p['external_id'] ?? null) === $external) {
                return $id;
            }
        }

        return null;
    }

    public function setProductRemote(string $productId, ?string $external, ?string $hash): void
    {
        $p = &$this->catalog->products[$productId];
        $p['external_id'] = $external ?? ($p['external_id'] ?? null);
        $p['remote_hash'] = $hash ?? ($p['remote_hash'] ?? null);
        $p['remote_synced'] = true;
        $p['needs_push'] = false;
    }

    public function productRemote(string $productId): ?array
    {
        return ['remote_hash' => $this->catalog->products[$productId]['remote_hash'] ?? null];
    }

    public function variantStates(string $productId): array
    {
        $o = [];
        foreach ($this->catalog->variants as $id => $v) {
            if ($v['product_id'] === $productId && ! $v['archived']) {
                $o[] = ['id' => $id, 'external_id' => $v['external_id'] ?? null, 'options' => $v['options'], 'stock_qty' => $v['stock_qty'], 'remote_stock' => $v['remote_stock'] ?? null, 'track_stock' => $v['track_stock']];
            }
        }

        return $o;
    }

    public function setVariantExternal(string $variantId, string $external): void
    {
        $this->catalog->variants[$variantId]['external_id'] = $external;
    }

    public function setVariantRemoteStock(string $variantId, ?int $qty): void
    {
        $this->catalog->variants[$variantId]['remote_stock'] = $qty;
    }

    public function pushCandidates(string $storeId, int $limit): array
    {
        $o = [];
        foreach ($this->catalog->products as $id => $p) {
            if (($p['status'] ?? '') === 'published' && (empty($p['remote_synced']) || ! empty($p['needs_push']))) {
                $o[] = ['id' => $id, 'external_id' => $p['external_id'] ?? null];
            }
        }

        return $o;
    }

    public function stockToPush(string $storeId, int $limit): array
    {
        $o = [];
        foreach ($this->catalog->variants as $id => $v) {
            $p = $this->catalog->products[$v['product_id']] ?? null;
            if ($p !== null && ! empty($p['live']) && ! $v['archived'] && $v['track_stock'] && ($v['external_id'] ?? null) !== null && (($v['remote_stock'] ?? null) === null || $v['remote_stock'] !== $v['stock_qty'])) {
                $o[] = ['id' => $id, 'external_id' => $v['external_id'], 'stock_qty' => $v['stock_qty']];
            }
        }

        return $o;
    }
}
