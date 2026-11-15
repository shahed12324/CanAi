<?php

declare(strict_types=1);

namespace App\Catalog\Stores;

use App\Outreach\HostGuard;

/** The store address is typed by a user and our server calls it, so it must be a public https address (no SSRF). */
final class StoreGuard
{
    /**
     * @param  callable(string): list<string>|null  $resolve  host => IPs (tests inject one)
     * @return string normalised base address (Woo: https://host[/path]; Shopify: the .myshopify.com host)
     *
     * @throws StoreException
     */
    public static function normalise(string $platform, string $input, ?callable $resolve = null): string
    {
        $input = trim($input);
        if ($platform === 'shopify') {
            $host = strtolower(preg_replace('#^https?://#i', '', $input) ?? $input);
            $host = rtrim(explode('/', $host)[0], '.');
            if (! ShopifyAdapter::validShop($host)) {
                throw new StoreException('invalid', 'Shopify The store address should be of the form mystore.myshopify.com.');
            }

            return $host;
        }
        if (! in_array($platform, ['woocommerce', 'jarvis_connector'], true)) {
            throw new StoreException('invalid', 'Unknown platform.');
        }
        $u = parse_url(preg_match('#^https?://#i', $input) === 1 ? $input : 'https://'.$input);
        if ($u === false || ($u['scheme'] ?? '') !== 'https' || empty($u['host']) || isset($u['user']) || isset($u['pass']) || isset($u['query']) || isset($u['fragment'])) {
            throw new StoreException('invalid', 'Give the store address in the form https://yourshop.com.');
        }
        if (isset($u['port']) && $u['port'] !== 443) {
            throw new StoreException('invalid', 'Only the normal https port (443) works.');
        }
        if (! HostGuard::allowed($u['host'], $resolve ?? [HostGuard::class, 'resolve'])) {
            throw new StoreException('invalid', 'This address is not on the public internet — it cannot be used.');
        }
        $path = rtrim((string) ($u['path'] ?? ''), '/');
        if ($path !== '' && (preg_match('#\A(/[A-Za-z0-9._~-]{1,40}){1,3}\z#', $path) !== 1 || preg_match('#/\.+(/|\z)#', $path) === 1)) {
            throw new StoreException('invalid', 'The address path is not valid.');
        }

        return 'https://'.strtolower($u['host']).$path;
    }
}
