<?php

// OAuth apps for "connect Gmail" / "connect Outlook". Leave empty to hide the buttons. Redirect URIs to register:
//   {APP_URL}/app/outreach/oauth/gmail/callback   and   {APP_URL}/app/outreach/oauth/graph/callback
return [
    'google' => [
        'client_id' => env('OUTREACH_GOOGLE_CLIENT_ID'),
        'client_secret' => env('OUTREACH_GOOGLE_CLIENT_SECRET'),
    ],
    'microsoft' => [
        'client_id' => env('OUTREACH_MS_CLIENT_ID'),
        'client_secret' => env('OUTREACH_MS_CLIENT_SECRET'),
        'tenant' => env('OUTREACH_MS_TENANT', 'common'),          // 'common' = any work or personal account
    ],
];
