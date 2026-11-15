<?php

/*
 | JARVIS Connector — settings for YOUR shop.
 |
 | 1. Run `php artisan jarvis-connector:secret`, put the printed line in .env, and give the same secret to JARVIS ONE.
 | 2. Tell the connector where your products live (the "columns" map below), then run `php artisan jarvis-connector:check`.
 | 3. In JARVIS ONE: Catalog > Store connections > JARVIS Connector, enter https://your-shop.com and the secret.
 |
 | The defaults below are only an EXAMPLE schema. Look at your own products table and change the names on the right.
 */
return [
    // Long random secret shared with JARVIS ONE (at least 32 characters). Empty = the API answers 503 and does nothing.
    'secret' => env('JARVIS_CONNECTOR_SECRET', ''),

    // Name shown in JARVIS when the connection is tested.
    'name' => env('JARVIS_CONNECTOR_NAME', env('APP_NAME', 'Store')),

    // Middleware for the routes. The 'api' group has no CSRF/session, which is what a server-to-server API needs.
    'middleware' => ['api'],

    // Your own ProductStore class (implements Jarvis\Connector\ProductStore). Leave null to use the column map below.
    'store' => null,

    // Database connection of the shop (null = default).
    'connection' => null,

    'database' => [
        'table' => 'products',
        'id' => 'id',
        'currency' => 'BDT',
        // 'major' = prices saved like 1250.50 (taka);  'minor' = saved as whole paisa (125050)
        'price_unit' => 'major',

        // left = what JARVIS knows, right = YOUR column name (null = your shop has no such column)
        'columns' => [
            'title' => 'name',
            'description' => 'details',
            'price' => 'price',
            // must be the FINAL discounted price. If your shop stores a discount percentage/amount instead, set null (sale price is then left alone).
            'sale_price' => null,
            'sku' => 'sku',
            'category' => null,
            'stock' => 'quantity',
            'images' => 'images',
            'tags' => null,
        ],

        // How images are stored in that column: json (["a.jpg","b.jpg"]) or csv (a.jpg,b.jpg). base_url is put in front of file names.
        // write=false (recommended): JARVIS never changes your images, it only reads them.
        'images' => ['format' => 'json', 'base_url' => null, 'write' => false],

        // Extra WHERE for products JARVIS may see, e.g. "status = 1" (written by you, never by a request).
        'where' => null,
        // Column that marks deleted rows (e.g. 'deleted_at'); those are hidden from JARVIS.
        'soft_delete' => null,
        // Column set to the current time when JARVIS changes a product (e.g. 'updated_at'), or null.
        'touch' => 'updated_at',
        // Columns your shop requires on a NEW product that JARVIS does not send, e.g. ['status' => 1, 'user_id' => 1].
        'insert_defaults' => [],
        // true: save the description as HTML (line breaks become <br>); false: plain text.
        'description_html' => false,
        // What to save in the sale price column when there is no sale (null or 0).
        'sale_empty' => null,

        // Variants table (null = products have no variants). Options must be stored as a JSON object like {"Size":"M","Color":"Red"}.
        'variants' => null,
        // 'variants' => ['table' => 'product_variants', 'id' => 'id', 'product_id' => 'product_id', 'sku' => 'sku', 'price' => 'price', 'stock' => 'quantity', 'options' => 'options'],
    ],
];
