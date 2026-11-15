<?php

declare(strict_types=1);

namespace App\Catalog\Stores;

interface StoreRepo
{
    /** @return array{id: string, tenant_id: string, platform: string, name: string, base_url: ?string, currency: string, status: string, last_sync_at: ?string, last_error: ?string}|null */
    public function store(string $id): ?array;

    /** @return list<array<string, mixed>> external stores only */
    public function stores(): array;

    public function createStore(string $tenantId, string $platform, string $name, string $baseUrl, string $cipher, int $keyVersion, string $currency): string;

    public function updateStore(string $id, string $name, string $baseUrl, ?string $cipher, ?int $keyVersion, string $currency): void;

    /** @return array{cipher: string, version: int}|null */
    public function credentials(string $id): ?array;

    public function setStatus(string $id, string $status, ?string $lastError, bool $touchSync): void;

    public function emergency(string $tenantId): bool;

    public function reap(string $storeId): void;

    /** @return string|null run id, null when a run is already going */
    public function beginRun(string $tenantId, string $storeId, string $trigger, ?string $userId): ?string;

    /** @param array{pulled: int, created: int, updated: int, pushed: int, failed: int} $c @param list<array{ref: string, message: string}> $errors */
    public function finishRun(string $runId, string $status, array $c, ?string $note, array $errors): void;

    /** @return list<array<string, mixed>> */
    public function runs(string $storeId, int $limit): array;

    public function idByExternal(string $storeId, string $external): ?string;

    public function setProductRemote(string $productId, ?string $external, ?string $hash): void;

    /** @return array{remote_hash: ?string}|null */
    public function productRemote(string $productId): ?array;

    /** @return list<array{id: string, external_id: ?string, options: array<string, string>, stock_qty: int, remote_stock: ?int, track_stock: bool}> */
    public function variantStates(string $productId): array;

    public function setVariantExternal(string $variantId, string $external): void;

    public function setVariantRemoteStock(string $variantId, ?int $qty): void;

    /** Published products whose approved copy is newer than the last sync. @return list<array{id: string, external_id: ?string}> */
    public function pushCandidates(string $storeId, int $limit): array;

    /** Variants whose local stock differs from what the store was last told. @return list<array{id: string, external_id: string, stock_qty: int}> */
    public function stockToPush(string $storeId, int $limit): array;
}
