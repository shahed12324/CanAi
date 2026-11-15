<?php

return [
    // Vendor's Ed25519 public key (64 hex chars). Override with JARVIS_LICENSE_PUBKEY. Empty = keys cannot be verified.
    'public_key' => env('JARVIS_LICENSE_PUBKEY', ''),
    'key_file' => env('JARVIS_LICENSE_FILE', storage_path('app/selfhost/license.key')),
];
