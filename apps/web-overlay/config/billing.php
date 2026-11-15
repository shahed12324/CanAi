<?php

// Payment gateways. A gateway is switched on only when its keys are present. See docs/ENVIRONMENT_SETUP.md ("Payments").
//   Return URL (customer):  {APP_URL}/billing/return/{gateway}      IPN / webhook URL:  {APP_URL}/billing/ipn/{gateway}
return [
    'demo' => (bool) env('BILLING_DEMO', false),           // pretend gateway: NO money moves. For demos / cPanel trials only.

    'sslcommerz' => [
        'store_id' => env('BILLING_SSLCOMMERZ_STORE_ID'),
        'store_password' => env('BILLING_SSLCOMMERZ_STORE_PASSWORD'),
        'sandbox' => (bool) env('BILLING_SSLCOMMERZ_SANDBOX', true),
    ],
    'bkash' => [
        'app_key' => env('BILLING_BKASH_APP_KEY'),
        'app_secret' => env('BILLING_BKASH_APP_SECRET'),
        'username' => env('BILLING_BKASH_USERNAME'),
        'password' => env('BILLING_BKASH_PASSWORD'),
        'sandbox' => (bool) env('BILLING_BKASH_SANDBOX', true),
        'base' => env('BILLING_BKASH_BASE'),               // optional override
    ],
    'nagad' => [
        'merchant_id' => env('BILLING_NAGAD_MERCHANT_ID'),
        'merchant_private_key' => env('BILLING_NAGAD_MERCHANT_PRIVATE_KEY'),   // PEM, or the base64 body only
        'pg_public_key' => env('BILLING_NAGAD_PG_PUBLIC_KEY'),                 // Nagad's public key
        'base' => env('BILLING_NAGAD_BASE', 'https://api.mynagad.com/api/dfs'), // must be https
    ],
    'stripe' => [
        'secret_key' => env('BILLING_STRIPE_SECRET_KEY'),
        'webhook_secret' => env('BILLING_STRIPE_WEBHOOK_SECRET'),
        'currencies' => array_filter(array_map('trim', explode(',', (string) env('BILLING_STRIPE_CURRENCIES', 'USD')))),
    ],
];
