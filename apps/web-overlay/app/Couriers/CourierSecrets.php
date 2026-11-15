<?php

declare(strict_types=1);

namespace App\Couriers;

use App\Ai\CredentialVault;

/** Seals a courier's keys with the platform vault; opens them only in memory. */
final class CourierSecrets
{
    public const FIELDS = [
        'steadfast' => ['api_key', 'secret_key'],
        'pathao' => ['client_id', 'client_secret', 'username', 'password'],
        'redx' => ['access_token'],
    ];

    public function __construct(private readonly CredentialVault $vault, private readonly CourierRepo $repo) {}

    /** @param array<string, string> $secrets @return array{cipher: string, version: int} */
    public function seal(string $courier, array $secrets): array
    {
        $keep = [];
        foreach (self::FIELDS[$courier] ?? [] as $f) {
            $v = trim((string) ($secrets[$f] ?? ''));
            if ($v === '' || mb_strlen($v) > 300) {
                throw new \InvalidArgumentException("missing $f");
            }
            $keep[$f] = $v;
        }
        if ($keep === []) {
            throw new \InvalidArgumentException('unknown courier');
        }

        return $this->vault->encrypt(json_encode($keep, JSON_THROW_ON_ERROR));
    }

    /** @return array{secrets: array<string, mixed>, courier: string, settings: array<string, mixed>, status: string}|null null when missing or undecryptable */
    public function open(string $accountId): ?array
    {
        $c = $this->repo->credentials($accountId);
        if ($c === null) {
            return null;
        }
        try {
            $a = json_decode($this->vault->decrypt($c['cipher'], $c['version']), true, 8, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return null;
        }

        return is_array($a) ? ['secrets' => $a, 'courier' => $c['courier'], 'settings' => $c['settings'], 'status' => $c['status']] : null;
    }
}
