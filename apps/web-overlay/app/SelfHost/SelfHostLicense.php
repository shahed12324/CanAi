<?php

declare(strict_types=1);

namespace App\SelfHost;

/** Reads the stored self-host licence key and reports a state the admin UI / commands / health check can show. */
final class SelfHostLicense
{
    public const WARN_DAYS = 14;

    public function __construct(private readonly string $keyFile, private readonly string $publicKeyHex) {}

    /**
     * @return array{state: string, reason: ?string, customer: ?string, seats: ?int, expires_at: ?int, days_left: ?int, features: list<string>}
     *                                                                                                                                              state: missing | valid | expiring | expired | invalid
     */
    public function status(?int $now = null): array
    {
        $now ??= time();
        $none = ['state' => 'missing', 'reason' => null, 'customer' => null, 'seats' => null, 'expires_at' => null, 'days_left' => null, 'features' => []];
        if (! is_file($this->keyFile)) {
            return $none;
        }
        $key = trim((string) file_get_contents($this->keyFile));
        if ($key === '') {
            return $none;
        }
        try {
            $l = LicenseKey::verify($key, $this->publicKeyHex, $now);
        } catch (LicenseKeyException $e) {
            return ['state' => $e->reason === 'expired' ? 'expired' : 'invalid', 'reason' => $e->reason] + $none;
        }
        $days = intdiv($l['expires_at'] - $now, 86400);

        return ['state' => $days < self::WARN_DAYS ? 'expiring' : 'valid', 'reason' => null, 'customer' => $l['customer'], 'seats' => $l['seats'], 'expires_at' => $l['expires_at'], 'days_left' => $days, 'features' => $l['features']];
    }

    /** Verify then store (0600). Nothing is written when the key is not valid. @throws LicenseKeyException */
    public function activate(string $key, ?int $now = null): array
    {
        $l = LicenseKey::verify($key, $this->publicKeyHex, $now);
        $dir = dirname($this->keyFile);
        if (! is_dir($dir)) {
            mkdir($dir, 0750, true);
        }
        file_put_contents($this->keyFile, trim($key)."\n", LOCK_EX);
        chmod($this->keyFile, 0600);

        return $l;
    }
}
