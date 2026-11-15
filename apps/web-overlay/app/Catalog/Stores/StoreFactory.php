<?php

declare(strict_types=1);

namespace App\Catalog\Stores;

use App\Ai\CredentialVault;
use App\Outreach\HttpJson;

class StoreFactory
{
    public function __construct(private readonly HttpJson $http, private readonly CredentialVault $vault, private readonly StoreRepo $stores, private readonly string $shopifyVersion = '2026-07') {}

    /** @param array<string, string> $creds woocommerce: key, secret — shopify: token */
    public function build(string $platform, string $baseUrl, array $creds, string $currency): StoreAdapter
    {
        return self::buildWith($this->http, $platform, $baseUrl, $creds, $currency, $this->shopifyVersion);
    }

    /** @param array<string, string> $creds */
    public static function buildWith(HttpJson $http, string $platform, string $baseUrl, array $creds, string $currency, string $shopifyVersion = '2026-07'): StoreAdapter
    {
        return match ($platform) {
            'woocommerce' => new WooAdapter($http, $baseUrl, (string) ($creds['key'] ?? ''), (string) ($creds['secret'] ?? ''), $currency),
            'jarvis_connector' => new ConnectorAdapter($http, $baseUrl, (string) ($creds['secret'] ?? ''), $currency),
            'shopify' => new ShopifyAdapter($http, $baseUrl, (string) ($creds['token'] ?? ''), $currency, $shopifyVersion),
            default => throw new StoreException('invalid', 'Unknown platform.'),
        };
    }

    /** @param array{id: string, platform: string, base_url: ?string, currency: string} $store */
    public function forStore(array $store): StoreAdapter
    {
        $c = $this->stores->credentials($store['id']);
        if ($c === null) {
            throw new StoreException('auth_failed');
        }
        try {
            $creds = json_decode($this->vault->decrypt($c['cipher'], $c['version']), true, 8, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw new StoreException('auth_failed');
        }

        return $this->build($store['platform'], (string) $store['base_url'], is_array($creds) ? $creds : [], $store['currency']);
    }

    /** @param array<string, string> $creds @return array{cipher: string, version: int} */
    public function seal(array $creds): array
    {
        return $this->vault->encrypt(json_encode($creds, JSON_THROW_ON_ERROR));
    }
}
