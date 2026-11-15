<?php

declare(strict_types=1);

namespace App\Catalog\Stores;

/**
 * One online store. A "remote product" everywhere below is the canonical array:
 *   external_id, title, description (plain text), price_minor, sale_price_minor, currency, sku, category, tags[], images[],
 *   variants[] = { external_id, sku, options{name=>value}, price_minor|null, stock|null }
 * `stock` null = the store does not track stock for that variant.
 */
interface StoreAdapter
{
    /** Proves the credentials work. @return string the store's name/host for display @throws StoreException */
    public function ping(): string;

    /** @return array{items: list<array<string, mixed>>, next: ?string} @throws StoreException */
    public function page(?string $cursor): array;

    /** @return array<string, mixed>|null @throws StoreException */
    public function fetch(string $externalId): ?array;

    /**
     * Creates (externalId null) or updates the store's product from the APPROVED copy.
     *
     * @param  array<string, mixed>  $product  title, description, price_minor, sale_price_minor, currency, sku, images[], tags[], category, variants[] = {sku, options, price_minor, stock}
     * @return string the store's id for the product
     *
     * @throws StoreException
     */
    public function push(array $product, ?string $externalId): string;

    /** @throws StoreException */
    public function pushStock(string $variantExternalId, int $qty): void;

    /** Stable text for an options map, used to match variants: {"Color":"Red","Size":"M"}. @param array<string, string> $options */
    public static function sig(array $options): string;
}
