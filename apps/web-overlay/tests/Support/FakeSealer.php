<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Social\TokenSealer;

/** A reversible stand-in for the vault (the real vault needs Laravel's encrypter). Different "keys" cannot open each other's output. */
final class FakeSealer implements TokenSealer
{
    public function __construct(private readonly string $key = 'k1') {}

    public function seal(string $token): array
    {
        return ['cipher' => 'sealed:'.$this->key.':'.strrev(base64_encode($token)), 'version' => 1];
    }

    public function open(string $cipher, int $version): ?string
    {
        $p = 'sealed:'.$this->key.':';
        if ($version !== 1 || ! str_starts_with($cipher, $p)) {
            return null;
        }
        $t = base64_decode(strrev(substr($cipher, strlen($p))), true);

        return $t === false || $t === '' ? null : $t;
    }
}
