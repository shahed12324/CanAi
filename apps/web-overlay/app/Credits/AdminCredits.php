<?php

declare(strict_types=1);

namespace App\Credits;

use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use InvalidArgumentException;

/**
 * Admin-only money operations. Must be given the `admin` connection: credit_adjust() and credit_reconcile()
 * check that the DB login is the admin login AND that the request is inside the admin panel (bypass flag).
 */
final class AdminCredits
{
    public function __construct(private readonly Connection $admin) {}

    /**
     * Manual correction (+/-) or bonus (+). $amount is a signed decimal string.
     *
     * @return array{tx_id: int, replayed: bool}
     */
    public function adjust(string $tenantId, string $type, string $amount, string $reason, string $idempotencyKey, string $actorId): array
    {
        if (preg_match('/\A(-?)(.+)\z/s', trim($amount), $m) !== 1) {
            throw new InvalidArgumentException('Invalid amount.');
        }
        $signed = ($m[1] === '-' ? '-' : '').CreditMath::normalize($m[2]);
        if (! CreditMath::validKey($idempotencyKey)) {
            throw new InvalidArgumentException('Invalid idempotency key.');
        }

        try {
            $row = $this->admin->selectOne(
                'select tx_id, replayed from credit_adjust(?::char(26), ?, ?::numeric, ?, ?, ?::char(26))',
                [$tenantId, $type, $signed, mb_substr(trim($reason), 0, 500), $idempotencyKey, $actorId],
            );
        } catch (QueryException $e) {
            throw CreditException::fromDatabase($e) ?? $e;
        }

        return ['tx_id' => (int) $row->tx_id, 'replayed' => (bool) $row->replayed];
    }

    /** @return array<int, object{tenant_id: string, check_name: string, expected: string, actual: string}> */
    public function reconcile(?string $tenantId = null): array
    {
        return $this->admin->select('select tenant_id, check_name, expected, actual from credit_reconcile(?::char(26))', [$tenantId]);
    }
}
