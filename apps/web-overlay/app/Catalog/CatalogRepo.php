<?php

declare(strict_types=1);

namespace App\Catalog;

/** Every workflow change goes through a database function; this interface is the only door the PHP side has. */
interface CatalogRepo
{
    /** The workspace's native store (created on first use). */
    public function ensureStore(string $tenantId): string;

    /** @return list<array<string, mixed>> */
    public function list(?string $status, ?string $search, int $limit): array;

    /** @return array<string, mixed>|null product row plus 'variants' (active) */
    public function find(string $id): ?array;

    /** @param array<string, mixed> $f */
    public function create(string $tenantId, ?string $userId, string $storeId, array $f): string;

    /** @param array<string, mixed> $f */
    public function update(string $id, array $f): void;

    public function idBySku(string $storeId, string $sku): ?string;

    /** @param array<string, string> $options */
    public function addVariant(string $tenantId, string $productId, ?string $sku, array $options, ?int $priceMinor, bool $track): string;

    /** @param array<string, mixed> $f sku, price_minor, track_stock */
    public function updateVariant(string $id, array $f): void;

    public function archiveVariant(string $id): void;

    /** @return string|null variant id for exactly these options */
    public function variantByOptions(string $productId, array $options): ?string;

    public function softDelete(string $id): void;

    /** @throws \RuntimeException message 'insufficient_stock' | 'not_tracked' | other */
    public function stock(string $variantId, int $delta, string $reason, ?string $note, ?string $userId): int;

    public function submit(string $id, string $userId): string;

    public function decide(string $id, string $userId, string $decision, ?string $note): string;

    public function publish(string $id, string $userId): string;

    public function archive(string $id, string $userId): string;

    public function restore(string $id, string $userId): string;

    /** @return list<array<string, mixed>> */
    public function events(string $id): array;

    /** @return list<array<string, mixed>> */
    public function movements(string $variantId, int $limit): array;

    /** @param list<array{row: int, message: string}> $errors */
    public function recordImport(string $tenantId, string $userId, string $filename, int $total, int $created, int $updated, int $failed, array $errors): void;

    /** @return list<array<string, mixed>> */
    public function imports(int $limit): array;
}
