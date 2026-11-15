<?php

return [
    'telegram_api' => env('TELEGRAM_API_BASE', 'https://api.telegram.org'),
    'messages_per_minute' => (int) env('MESSAGING_MESSAGES_PER_MINUTE', 20),
    'show_cost' => (bool) env('MESSAGING_SHOW_COST', true),
    'max_file_bytes' => 204800,
];
