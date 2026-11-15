<?php

return [
    'url' => env('AI_ENGINE_URL', 'http://ai-engine:8000'),
    'hmac_secret' => env('AI_ENGINE_HMAC_SECRET'),
    'jwt_secret' => env('AI_ENGINE_JWT_SECRET'),
    'timeout' => (int) env('AI_ENGINE_TIMEOUT', 10),

    // Provider credential vault. Separate from APP_KEY so a leaked APP_KEY alone does not open it.
    // Keys are versioned for rotation: add AI_VAULT_KEY_2, set AI_VAULT_CURRENT=2, re-save credentials.
    'vault' => [
        'current' => (int) env('AI_VAULT_CURRENT', 1),
        'keys' => array_filter([
            1 => env('AI_VAULT_KEY_1'),
            2 => env('AI_VAULT_KEY_2'),
            3 => env('AI_VAULT_KEY_3'),
        ]),
    ],
];
