<?php

declare(strict_types=1);

namespace Jarvis\Connector;

/**
 * What the connector needs from YOUR shop. The bundled DatabaseProductStore covers a typical products table through a column map;
 * if your shop is different, implement this interface and set `store` in config/jarvis-connector.php.
 *
 * A "product" everywhere is:
 *   external_id, title, description (plain text), price_minor (int, paisa), sale_price_minor (?int), currency, sku, category, tags[], images[] (https urls),
 *   variants[] = { external_id, sku, options{name=>value}, price_minor (?int, null = product price), stock (?int, null = not tracked) }
 * Variant external_id: a product without variants uses the product id; a variant uses "productId/variantId".
 */
interface ProductStore
{
    /** @return array{items: list<array<string, mixed>>, next: ?string} */
    public function page(?string $cursor, int $limit): array;

    /** @return array<string, mixed>|null */
    public function find(string $id): ?array;

    /**
     * Creates ($id null) or updates a product and returns its id.
     *
     * @param  array<string, mixed>  $product
     *
     * @throws \InvalidArgumentException when the shop cannot accept it (becomes HTTP 422)
     * @throws \OutOfBoundsException when $id does not exist (HTTP 404)
     */
    public function upsert(?string $id, array $product): string;

    /** @throws \OutOfBoundsException when the variant does not exist (HTTP 404) */
    public function setStock(string $variantExternalId, int $qty): void;
}
