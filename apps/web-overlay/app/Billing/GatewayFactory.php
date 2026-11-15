<?php

declare(strict_types=1);

namespace App\Billing;

use App\Billing\Gateways\Bkash;
use App\Billing\Gateways\Demo;
use App\Billing\Gateways\Nagad;
use App\Billing\Gateways\Rsa;
use App\Billing\Gateways\SslCommerz;
use App\Billing\Gateways\Stripe;
use App\Outreach\HttpJson;

/** Builds the gateways whose credentials are configured. Pure: takes the config array, so it is testable without Laravel. */
final class GatewayFactory
{
    /** @param array<string, mixed> $c config('billing') */
    public static function make(array $c, HttpJson $http, string $appKey, \Closure $demoPageUrl): GatewayRegistry
    {
        $list = [];
        $ssl = $c['sslcommerz'] ?? [];
        if (! empty($ssl['store_id']) && ! empty($ssl['store_password'])) {
            $list[] = new SslCommerz($http, (string) $ssl['store_id'], (string) $ssl['store_password'], (bool) ($ssl['sandbox'] ?? true));
        }
        $bk = $c['bkash'] ?? [];
        if (! empty($bk['app_key']) && ! empty($bk['app_secret']) && ! empty($bk['username']) && ! empty($bk['password'])) {
            $list[] = new Bkash($http, (string) $bk['app_key'], (string) $bk['app_secret'], (string) $bk['username'], (string) $bk['password'], (bool) ($bk['sandbox'] ?? true), ($bk['base'] ?? null) ?: null);
        }
        $ng = $c['nagad'] ?? [];
        if (! empty($ng['merchant_id']) && ! empty($ng['merchant_private_key']) && ! empty($ng['pg_public_key']) && str_starts_with((string) ($ng['base'] ?? 'https://'), 'https://')) {
            $list[] = new Nagad($http, (string) $ng['merchant_id'],
                new Rsa(Rsa::pem((string) $ng['merchant_private_key'], 'PRIVATE KEY'), Rsa::pem((string) $ng['pg_public_key'], 'PUBLIC KEY')), (string) $ng['base']);
        }
        $st = $c['stripe'] ?? [];
        if (! empty($st['secret_key']) && ! empty($st['webhook_secret'])) {
            $list[] = new Stripe($http, (string) $st['secret_key'], (string) $st['webhook_secret'], array_values(array_map('strtoupper', $st['currencies'] ?? ['USD'])));
        }
        if (! empty($c['demo'])) {
            $list[] = new Demo(hash('sha256', 'billing-demo|'.$appKey), $demoPageUrl);
        }

        return new GatewayRegistry($list);
    }
}
