<?php

declare(strict_types=1);

namespace App\Billing\Gateways;

use App\Billing\GatewayFailure;

/** The three RSA operations Nagad needs. Keys are PEM text (a bare base64 body is wrapped). */
final class Rsa
{
    public function __construct(private readonly string $privatePem, private readonly string $publicPem) {}

    public static function pem(string $key, string $label): string
    {
        $key = trim($key);
        if (str_contains($key, '-----BEGIN')) {
            return $key;
        }

        return "-----BEGIN {$label}-----\n".chunk_split(preg_replace('/\s+/', '', $key) ?? '', 64, "\n")."-----END {$label}-----";
    }

    /** Encrypt with the OTHER side's public key (PKCS#1 v1.5), base64. */
    public function encryptForPeer(string $plain): string
    {
        $k = openssl_pkey_get_public($this->publicPem);
        if ($k === false || ! openssl_public_encrypt($plain, $out, $k, OPENSSL_PKCS1_PADDING)) {
            throw new GatewayFailure('rsa encrypt failed');
        }

        return base64_encode($out);
    }

    /** Decrypt something the peer encrypted for us. */
    public function decrypt(string $b64): string
    {
        $k = openssl_pkey_get_private($this->privatePem);
        $raw = base64_decode($b64, true);
        if ($k === false || $raw === false || ! openssl_private_decrypt($raw, $out, $k, OPENSSL_PKCS1_PADDING)) {
            throw new GatewayFailure('rsa decrypt failed');
        }

        return $out;
    }

    public function sign(string $plain): string
    {
        $k = openssl_pkey_get_private($this->privatePem);
        if ($k === false || ! openssl_sign($plain, $sig, $k, OPENSSL_ALGO_SHA256)) {
            throw new GatewayFailure('rsa sign failed');
        }

        return base64_encode($sig);
    }
}
