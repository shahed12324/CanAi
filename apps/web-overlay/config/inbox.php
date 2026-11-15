<?php

return [
    // Meta Graph API version used for Messenger / Instagram / WhatsApp. Check Meta's changelog and bump this when a version is retired.
    'graph_version' => env('INBOX_GRAPH_VERSION', 'v21.0'),
    'dispatch_batch' => (int) env('INBOX_DISPATCH_BATCH', 50),
];
