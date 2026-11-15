<?php

declare(strict_types=1);

namespace App\Credits;

/** What the chat flow needs from the credit engine; lets the money-critical orchestration be tested with a fake. */
interface CreditLedger
{
    /** @return array{reservation_id: string, replayed: bool} */
    public function reserve(string $tenantId, string|int $amount, string $idempotencyKey, ?string $requestId = null, ?int $ttlSeconds = null): array;

    /**
     * @param  array<string, scalar|null>  $usage
     * @return array{tx_id: int, charged: string, shortfall: string, replayed: bool}
     */
    public function settle(string $tenantId, string $reservationId, string|int $actual, string $idempotencyKey, array $usage = []): array;

    /** @return array{tx_id: int, replayed: bool} */
    public function release(string $tenantId, string $reservationId, string $idempotencyKey): array;
}
