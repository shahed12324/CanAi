<?php

// Read through config() (not env()) so `php artisan config:cache` in production keeps working.
return [
    'username' => env('DB_ADMIN_USERNAME', 'jarvis_admin_login'),
    'password' => env('DB_ADMIN_PASSWORD'),
];
