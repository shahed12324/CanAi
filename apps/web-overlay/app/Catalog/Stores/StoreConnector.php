<?php

declare(strict_types=1);

namespace App\Catalog\Stores;

final class StoreConnector
{
    public function __construct(private readonly StoreRepo $stores, private readonly StoreFactory $factory, private readonly mixed $resolve = null) {}

    /**
     * Checks the address, proves the key works (one read call) and only then stores it, encrypted.
     *
     * @param  array<string, string>  $creds
     * @return array{id?: string, error?: string}
     */
    public function connect(string $tenantId, string $platform, string $name, string $address, array $creds, string $currency): array
    {
        $name = trim($name);
        $currency = strtoupper(trim($currency) ?: 'BDT');
        if ($name === '' || mb_strlen($name) > 120) {
            return ['error' => 'Give the store name.'];
        }
        if (preg_match('/\A[A-Z]{3}\z/', $currency) !== 1) {
            return ['error' => 'The currency code is 3 letters (BDT).'];
        }
        $clean = [];
        foreach ($platform === 'shopify' ? ['token'] : ($platform === 'jarvis_connector' ? ['secret'] : ['key', 'secret']) as $k) {
            $v = trim((string) ($creds[$k] ?? ''));
            if ($v === '' || strlen($v) > 300 || preg_match('/\s/', $v) === 1 || ($platform === 'jarvis_connector' && strlen($v) < 32)) {
                return ['error' => 'Give the key/token (not blank or spaces).'];
            }
            $clean[$k] = $v;
        }
        try {
            $base = StoreGuard::normalise($platform, $address, $this->resolve);
            $this->factory->build($platform, $base, $clean, $currency)->ping();
            $sealed = $this->factory->seal($clean);
        } catch (StoreException $e) {
            return ['error' => $e->userMessage()];
        }

        return ['id' => $this->stores->createStore($tenantId, $platform, mb_substr($name, 0, 120), $base, $sealed['cipher'], $sealed['version'], $currency)];
    }
}
