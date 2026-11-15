<?php

// Test helper: prints signed headers as JSON. Used by the Python cross-compat test.
// usage: php cli_sign.php METHOD PATH QUERY BODY HMAC_SECRET JWT_SECRET TENANT
declare(strict_types=1);

require __DIR__.'/../app/Services/AiEngine/RequestSigner.php';

[, $method, $path, $query, $body, $hmac, $jwt, $tenant] = $argv;

echo json_encode(
    (new App\Services\AiEngine\RequestSigner($hmac, $jwt))->headers($method, $path, $query, $body, $tenant),
    JSON_THROW_ON_ERROR,
);
