<?php

declare(strict_types=1);

namespace App\Credits;

use App\Tenancy\TenantContext;
use Closure;
use DateTimeInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The ONLY door to money. Every method calls a SECURITY DEFINER SQL function; the app's database
 * login has no INSERT/UPDATE/DELETE on any credit table, so there is no other way to move credits.
 *
 * Rules the callers must follow:
 *  - Amounts are decimal strings (see CreditMath), never floats.
 *  - Every call carries an idempotency key; retrying with the same key is safe and returns the original result.
 *  - Do not call inside a DB transaction that may roll back after the tenant context was set.
 *  - grant() with 'purchase'/'refund' belongs to the payment flow ONLY, after the provider has confirmed.
 */
final class CreditService implements CreditLedger
{
    public function __construct(private readonly TenantContext $context) {}

    /**
     * @param  'grant_free'|'grant_plan'|'purchase'|'refund'  $type
     * @return array{tx_id: int, replayed: bool}
     */
    public function grant(
        string $tenantId,
        string $type,
        string|int $amount,
        string $idempotencyKey,
        ?string $referenceType = null,
        ?string $referenceId = null,
        ?DateTimeInterface $expiresAt = null,
        ?string $actorId = null,
        ?string $note = null,
    ): array {
        $amount = CreditMath::normalize($amount);
        $this->guardKey($idempotencyKey);

        $row = $this->in($tenantId, fn () => DB::selectOne(
            'select tx_id, replayed from credit_grant(?::char(26), ?, ?::numeric, ?, ?, ?, ?::timestamptz, ?::char(26), ?)',
            [$tenantId, $type, $amount, $idempotencyKey, $referenceType, $referenceId, $expiresAt?->format('Y-m-d H:i:sP'), $actorId, $note !== null ? mb_substr($note, 0, 500) : null],
        ));

        return ['tx_id' => (int) $row->tx_id, 'replayed' => (bool) $row->replayed];
    }

    /** Holds credits for one in-flight request. Returns the reservation id. @return array{reservation_id: string, replayed: bool} */
    public function reserve(string $tenantId, string|int $amount, string $idempotencyKey, ?string $requestId = null, ?int $ttlSeconds = null): array
    {
        $amount = CreditMath::normalize($amount);
        $this->guardKey($idempotencyKey);
        $ttl = $ttlSeconds ?? (int) config('credits.reservation_ttl', 900);

        $row = $this->in($tenantId, fn () => DB::selectOne(
            'select reservation_id, replayed from credit_reserve(?::char(26), ?::numeric, ?, ?, ?::integer)',
            [$tenantId, $amount, $idempotencyKey, $requestId, $ttl],
        ));

        return ['reservation_id' => (string) $row->reservation_id, 'replayed' => (bool) $row->replayed];
    }

    /**
     * Charges the real cost of a finished request and writes its usage record.
     *
     * @param  array<string, scalar|null>  $usage  keys: source, user_id, request_id, provider_id, model_id, agent_id, department,
     *                                             input_tokens, output_tokens, units, unit_type, provider_cost, provider_currency
     * @return array{tx_id: int, charged: string, shortfall: string, replayed: bool}
     */
    public function settle(string $tenantId, string $reservationId, string|int $actual, string $idempotencyKey, array $usage = []): array
    {
        $actual = CreditMath::normalize($actual);
        $this->guardKey($idempotencyKey);
        $this->guardId($reservationId);

        $row = $this->in($tenantId, fn () => DB::selectOne(
            'select tx_id, charged, shortfall, replayed from credit_settle(?::char(26), ?::numeric, ?, ?::jsonb)',
            [$reservationId, $actual, $idempotencyKey, json_encode($usage, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)],
        ));

        return ['tx_id' => (int) $row->tx_id, 'charged' => (string) $row->charged, 'shortfall' => (string) $row->shortfall, 'replayed' => (bool) $row->replayed];
    }

    /** Gives the held credits back (request failed or was cancelled). @return array{tx_id: int, replayed: bool} */
    public function release(string $tenantId, string $reservationId, string $idempotencyKey): array
    {
        $this->guardKey($idempotencyKey);
        $this->guardId($reservationId);

        $row = $this->in($tenantId, fn () => DB::selectOne(
            'select tx_id, replayed from credit_release(?::char(26), ?)',
            [$reservationId, $idempotencyKey],
        ));

        return ['tx_id' => (int) $row->tx_id, 'replayed' => (bool) $row->replayed];
    }

    /** Spending guard settings; null clears. Amounts are decimal strings. */
    public function configure(string $tenantId, string|int|null $lowBalanceThreshold, string|int|null $monthlyLimit): void
    {
        $t = $lowBalanceThreshold === null ? null : CreditMath::normalize($lowBalanceThreshold);
        $l = $monthlyLimit === null ? null : CreditMath::normalize($monthlyLimit);

        $this->in($tenantId, fn () => DB::select('select credit_wallet_configure(?::char(26), ?::numeric, ?::numeric)', [$tenantId, $t, $l]));
    }

    /** @return object{balance: string, reserved: string, available: string, usable: string, low_balance_threshold: ?string, spending_limit_monthly: ?string}|null */
    public function wallet(string $tenantId): ?object
    {
        return $this->in($tenantId, fn () => DB::selectOne(
            'select w.balance, w.reserved, greatest(w.balance - w.reserved, 0) as available, credit_usable(w.id) as usable,
                    w.low_balance_threshold, w.spending_limit_monthly
               from wallets w where w.tenant_id = ?',
            [$tenantId],
        ));
    }

    /** Credits charged in the current calendar month (the figure the spending limit compares against). */
    public function spentThisMonth(string $tenantId): string
    {
        return (string) $this->in($tenantId, fn () => DB::scalar(
            "select coalesce(sum(credits_charged), 0) from usage_records where tenant_id = ? and created_at >= date_trunc('month', now())",
            [$tenantId],
        ));
    }

    /** Free credits for a brand-new workspace. Safe to call twice (same key). */
    public function grantSignup(string $tenantId): ?array
    {
        $amount = (string) config('credits.signup_grant', '0');
        if ($amount === '' || ! CreditMath::isPositive(CreditMath::normalize($amount))) {
            return null;
        }
        $days = config('credits.signup_grant_days');

        return $this->grant(
            $tenantId, 'grant_free', $amount, 'signup:'.$tenantId, 'signup', $tenantId,
            $days === null ? null : now()->addDays((int) $days),
            null, 'Welcome credits',
        );
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $fn
     * @return T
     */
    private function in(string $tenantId, Closure $fn): mixed
    {
        try {
            return $this->context->run($tenantId, $fn);
        } catch (QueryException $e) {
            throw CreditException::fromDatabase($e) ?? $e;
        }
    }

    private function guardKey(string $key): void
    {
        if (! CreditMath::validKey($key)) {
            throw new InvalidArgumentException('Idempotency key must be 8-128 chars of [A-Za-z0-9:_.-].');
        }
    }

    private function guardId(string $id): void
    {
        if (preg_match('/\A[0-9a-f]{26}\z/', $id) !== 1) {
            throw new InvalidArgumentException('Invalid reservation id.');
        }
    }
}
