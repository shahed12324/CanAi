<?php

use App\Services\AiEngine\RequestSigner;

$hmac = str_repeat('h', 40);
$jwt = str_repeat('j', 40);

it('rejects short secrets', function () {
    new RequestSigner('short', str_repeat('j', 40));
})->throws(InvalidArgumentException::class);

it('produces deterministic signature for fixed inputs', function () use ($hmac, $jwt) {
    $s = new RequestSigner($hmac, $jwt);
    $a = $s->headers('GET', '/x', 'b=2&a=1', '', 'T', null, 1700000000, 'nonce');
    $b = $s->headers('GET', '/x', 'a=1&b=2', '', 'T', null, 1700000000, 'nonce');
    expect($a['X-Signature'])->toBe($b['X-Signature']);
});

it('binds the body and the path into the signature', function () use ($hmac, $jwt) {
    $s = new RequestSigner($hmac, $jwt);
    $base = $s->headers('POST', '/x', '', 'a', 'T', null, 1700000000, 'n')['X-Signature'];
    expect($s->headers('POST', '/x', '', 'b', 'T', null, 1700000000, 'n')['X-Signature'])->not->toBe($base);
    expect($s->headers('POST', '/y', '', 'a', 'T', null, 1700000000, 'n')['X-Signature'])->not->toBe($base);
});

it('issues a 60 second token whose jti equals the nonce', function () use ($hmac, $jwt) {
    $h = (new RequestSigner($hmac, $jwt))->headers('GET', '/x', '', '', 'T', null, 1700000000, 'abc');
    [, $payload] = explode('.', substr($h['Authorization'], 7));
    $claims = json_decode(base64_decode(strtr($payload, '-_', '+/')), true);
    expect($claims['exp'] - $claims['iat'])->toBe(60)->and($claims['jti'])->toBe('abc');
});
