<?php

return [
    // Shopify Admin API version used for every call (a version is supported for about a year; bump after checking Shopify's changelog).
    'shopify_api_version' => env('SHOPIFY_API_VERSION', '2026-07'),
    // How often the scheduler re-syncs each connected store.
    'sync_every_minutes' => (int) env('CATALOG_SYNC_EVERY_MINUTES', 15),
];
