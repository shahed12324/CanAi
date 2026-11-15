<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Credits\CreditException;
use App\Credits\CreditLedger;

final class FakeLedger implements CreditLedger
{
    public string $balance;

    public string $held = '0.0000';

    /** @var array<string, array{amount: string, state: string}> */
    public array $reservations = [];

    /** @var list<string> */
    public array $log = [];

    public bool $failSettle = false;

    /** @var list<string> idempotency keys given to release() */
    public array $releaseKeys = [];

    /** @var list<string> */
    public array $settleKeys = [];

    /** @var list<string> */
    public array $reserveKeys = [];

    /** @var list<array<string, mixed>> usage arrays given to settle() */
    public array $usages = [];

    public function __construct(string $balance = '100.0000')
    {
        $this->balance = $balance;
    }

    public function reserve(string $tenantId, string|int $amount, string $idempotencyKey, ?string $requestId = null, ?int $ttlSeconds = null): array
    {
        $this->reserveKeys[] = $idempotencyKey;
        $amount = number_format((float) $amount, 4, '.', '');
        if (((float) $this->balance - (float) $this->held) < (float) $amount) {
            throw new CreditException('insufficient_credits');
        }
        $id = str_pad((string) (count($this->reservations) + 1), 26, 'r', STR_PAD_LEFT);
        $this->reservations[$id] = ['amount' => $amount, 'state' => 'held'];
        $this->held = number_format((float) $this->held + (float) $amount, 4, '.', '');
        $this->log[] = "reserve:$amount";

        return ['reservation_id' => $id, 'replayed' => false];
    }

    public function settle(string $tenantId, string $reservationId, string|int $actual, string $idempotencyKey, array $usage = []): array
    {
        if ($this->failSettle) {
            throw new \RuntimeException('db down');
        }
        $this->usages[] = $usage;
        $this->settleKeys[] = $idempotencyKey;
        $r = &$this->reservations[$reservationId];
        $actual = number_format((float) $actual, 4, '.', '');
        $charge = min((float) $actual, (float) $r['amount']);
        $this->held = number_format((float) $this->held - (float) $r['amount'], 4, '.', '');
        $this->balance = number_format((float) $this->balance - $charge, 4, '.', '');
        $r['state'] = 'settled';
        $this->log[] = "settle:$actual";

        return ['tx_id' => 1, 'charged' => number_format($charge, 4, '.', ''), 'shortfall' => '0.0000', 'replayed' => false];
    }

    public function release(string $tenantId, string $reservationId, string $idempotencyKey): array
    {
        $this->releaseKeys[] = $idempotencyKey;
        $r = &$this->reservations[$reservationId];
        if ($r['state'] === 'held') {
            $this->held = number_format((float) $this->held - (float) $r['amount'], 4, '.', '');
            $r['state'] = 'released';
        }
        $this->log[] = 'release';

        return ['tx_id' => 2, 'replayed' => false];
    }

    public function openReservations(): int
    {
        return count(array_filter($this->reservations, fn ($r) => $r['state'] === 'held'));
    }
}
