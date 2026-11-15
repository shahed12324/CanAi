<?php

declare(strict_types=1);

namespace App\Reseller;

/** Joining a reseller (by typing their code) and the reseller's own figures, in plain words. */
final class ResellerService
{
    public const MESSAGES = [
        'ok' => 'You have joined the reseller.',
        'already' => 'This workspace is already linked to a reseller — it cannot be changed.',
        'not_found' => 'There is no active reseller with this code.',
        'not_owner' => 'Only the workspace owner can enter a reseller code.',
        'self' => 'You cannot use your own reseller code yourself.',
    ];

    public function __construct(private readonly ResellerRepo $repo) {}

    public static function message(string $code): string
    {
        return self::MESSAGES[$code] ?? 'The action could not be done.';
    }

    /** Spaces and dashes people type are dropped; the code is letters and digits only. */
    public static function cleanCode(string $raw): string
    {
        return strtoupper((string) preg_replace('/[\s\-]+/u', '', trim($raw)));
    }

    public function join(string $tenantId, string $userId, string $rawCode): string
    {
        $code = self::cleanCode($rawCode);
        if (preg_match('/\A[A-Z0-9]{6,12}\z/', $code) !== 1) {
            return 'not_found';                           // never sent to the database
        }

        return $this->repo->join($tenantId, $userId, $code);
    }

    public function joinedTo(string $tenantId, string $userId): ?string
    {
        return $this->repo->of($tenantId, $userId);
    }

    public function me(string $tenantId, string $userId): ?array
    {
        return $this->repo->me($tenantId, $userId);
    }

    /** @return ?array{customers: list<array<string, mixed>>, money: list<array{currency: string, revenue: string, commission: string, paid_out: string, due: string}>} */
    public function report(string $tenantId, string $userId): ?array
    {
        $r = $this->repo->report($tenantId, $userId);
        if ($r === null) {
            return null;
        }
        $money = [];
        ksort($r['money']);
        foreach ($r['money'] as $cur => $m) {
            $money[] = ['currency' => (string) $cur] + array_map(fn ($v) => self::fmt((int) $v), $m);
        }

        return ['customers' => $r['customers'], 'money' => $money];
    }

    /** Minor units (paisa/cents) as "12.34"; negative stays negative. */
    public static function fmt(int $minor): string
    {
        return ($minor < 0 ? '-' : '').intdiv(abs($minor), 100).'.'.str_pad((string) (abs($minor) % 100), 2, '0', STR_PAD_LEFT);
    }
}
