<?php

declare(strict_types=1);

namespace App\SelfHost;

/**
 * Offline, tamper-proof self-host licence keys (Ed25519). No licence server is contacted: the vendor signs
 * {customer, seats, issued, expires} with a secret key kept off the customer's server; the server only holds the public key.
 * Key text:  JVSH1.<base64url(payload json)>.<base64url(signature)>   — the signature covers "JVSH1." + the payload segment verbatim.
 */
final class LicenseKey
{
    public const PREFIX = 'JVSH1';

    /** @return array{public: string, secret: string} hex strings */
    public static function keypair(): array
    {
        $kp = sodium_crypto_sign_keypair();

        return ['public' => sodium_bin2hex(sodium_crypto_sign_publickey($kp)), 'secret' => sodium_bin2hex(sodium_crypto_sign_secretkey($kp))];
    }

    /** @param list<string> $features */
    public static function issue(string $secretHex, string $customer, int $seats, int $issuedAt, int $expiresAt, array $features = []): string
    {
        $customer = trim($customer);
        if ($customer === '' || mb_strlen($customer) > 120 || $seats < 1 || $seats > 100000 || $expiresAt <= $issuedAt) {
            throw new \InvalidArgumentException('invalid licence parameters');
        }
        $payload = self::b64(json_encode(['v' => 1, 'c' => $customer, 'n' => $seats, 'iat' => $issuedAt, 'exp' => $expiresAt, 'f' => array_values($features)], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $sig = sodium_crypto_sign_detached(self::PREFIX.'.'.$payload, sodium_hex2bin($secretHex));

        return self::PREFIX.'.'.$payload.'.'.self::b64($sig);
    }

    /**
     * @return array{customer: string, seats: int, issued_at: int, expires_at: int, features: list<string>}
     *
     * @throws LicenseKeyException
     */
    public static function verify(string $key, string $publicHex, ?int $now = null): array
    {
        $now ??= time();
        if (! preg_match('/^[0-9a-f]{64}$/', $publicHex)) {
            throw new LicenseKeyException('no_public_key');
        }
        $parts = explode('.', trim($key));
        if (count($parts) !== 3 || $parts[0] !== self::PREFIX || $parts[1] === '' || $parts[2] === '') {
            throw new LicenseKeyException('malformed');
        }
        $sig = self::unb64($parts[2]);
        if ($sig === null || strlen($sig) !== SODIUM_CRYPTO_SIGN_BYTES) {
            throw new LicenseKeyException('malformed');
        }
        if (! sodium_crypto_sign_verify_detached($sig, self::PREFIX.'.'.$parts[1], sodium_hex2bin($publicHex))) {
            throw new LicenseKeyException('bad_signature');
        }
        $json = self::unb64($parts[1]);
        $p = $json === null ? null : json_decode($json, true);
        if (! is_array($p) || ($p['v'] ?? null) !== 1 || ! is_string($p['c'] ?? null) || ! is_int($p['n'] ?? null) || ! is_int($p['iat'] ?? null) || ! is_int($p['exp'] ?? null) || $p['n'] < 1 || ! is_array($p['f'] ?? null)) {
            throw new LicenseKeyException('bad_payload');
        }
        if ($p['iat'] > $now + 300) {   // 5 min clock-skew tolerance
            throw new LicenseKeyException('not_yet_valid');
        }
        if ($p['exp'] <= $now) {
            throw new LicenseKeyException('expired');
        }

        return ['customer' => $p['c'], 'seats' => $p['n'], 'issued_at' => $p['iat'], 'expires_at' => $p['exp'], 'features' => array_values(array_map('strval', $p['f']))];
    }

    private static function b64(string $s): string
    {
        return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    }

    private static function unb64(string $s): ?string
    {
        if (! preg_match('/^[A-Za-z0-9_-]+$/', $s)) {
            return null;
        }
        $r = base64_decode(strtr($s, '-_', '+/'), true);

        return $r === false ? null : $r;
    }
}
