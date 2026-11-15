<?php

use App\Ai\CredentialVault;

function vault(array $keys = null, int $current = 1): CredentialVault
{
    return new CredentialVault($keys ?? [1 => CredentialVault::generateKey()], $current);
}

it('round-trips a credential and never stores plaintext', function () {
    $v = vault();
    $e = $v->encrypt('sk-live-ABCDEFG12345');
    expect($e['version'])->toBe(1)
        ->and($e['cipher'])->not->toContain('sk-live')
        ->and($v->decrypt($e['cipher'], 1))->toBe('sk-live-ABCDEFG12345');
});

it('uses a fresh nonce each time', function () {
    $v = vault();
    expect($v->encrypt('same')['cipher'])->not->toBe($v->encrypt('same')['cipher']);
});

it('cannot be opened with another key, and the error leaks nothing', function () {
    $e = vault()->encrypt('sk-secret-value');
    try {
        vault()->decrypt($e['cipher'], 1);
        $this->fail('should not decrypt');
    } catch (RuntimeException $ex) {
        expect($ex->getMessage())->not->toContain('sk-secret-value')->and($ex->getMessage())->not->toContain($e['cipher']);
    }
});

it('detects tampering (GCM)', function () {
    $v = vault();
    $e = $v->encrypt('abcdef');
    $payload = json_decode(base64_decode($e['cipher']), true);
    $payload['value'] = base64_encode('x'.base64_decode($payload['value']));
    $v->decrypt(base64_encode(json_encode($payload)), 1);
})->throws(RuntimeException::class);

it('supports key rotation: old ciphertext still opens, new writes use the new version', function () {
    $k1 = CredentialVault::generateKey();
    $k2 = CredentialVault::generateKey();
    $old = vault([1 => $k1], 1)->encrypt('legacy');
    $rotated = vault([1 => $k1, 2 => $k2], 2);

    expect($rotated->decrypt($old['cipher'], 1))->toBe('legacy')
        ->and($rotated->encrypt('fresh')['version'])->toBe(2)
        ->and($rotated->needsRotation(1))->toBeTrue()
        ->and($rotated->needsRotation(2))->toBeFalse();
});

it('rejects bad configuration', function () {
    expect(fn () => vault([], 1))->toThrow(InvalidArgumentException::class);
    expect(fn () => vault([1 => 'base64:'.base64_encode('short')], 1))->toThrow(InvalidArgumentException::class);
    expect(fn () => vault([1 => 'not-base64-prefixed'], 1))->toThrow(InvalidArgumentException::class);
    expect(fn () => vault([1 => CredentialVault::generateKey()], 2))->toThrow(InvalidArgumentException::class);
    expect(fn () => vault()->encrypt(''))->toThrow(InvalidArgumentException::class);
});

it('hides its keys from dumps', function () {
    $k = CredentialVault::generateKey();
    ob_start();
    var_dump(new CredentialVault([1 => $k], 1));
    $out = ob_get_clean();
    expect($out)->not->toContain(substr($k, 7, 16));
});
