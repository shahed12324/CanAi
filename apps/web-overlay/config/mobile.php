<?php

return [
    // Oldest app build the API still talks to; the app shows "please update" below it.
    'min_app_version' => env('MOBILE_MIN_APP_VERSION', '1.0.0'),
    // 'expo' sends push through Expo's service (which relays to FCM / APNs); 'none' keeps notifications inside the app only.
    'push' => env('MOBILE_PUSH', 'expo'),
    'expo_access_token' => env('EXPO_ACCESS_TOKEN'),
];
