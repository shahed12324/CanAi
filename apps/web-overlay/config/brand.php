<?php

// Single source of the product name (ADR-015). Never hard-code the brand elsewhere.
return [
    'name' => env('BRAND_NAME', 'CANAI'),
    'short_name' => env('BRAND_SHORT_NAME', 'CANAI'),
    'support_email' => env('BRAND_SUPPORT_EMAIL', 'support@example.com'),
];
