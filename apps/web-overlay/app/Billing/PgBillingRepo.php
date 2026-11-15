<?php

declare(strict_types=1);

namespace App\Billing;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/** Raw SQL on purpose: these are thin calls to the billing_* functions, executed against real Postgres in tests/db. */
final class PgBillingRepo implements BillingRepo
{
    private function wrap(\Closure $f): mixed
    {
        try {
            return $f();
        } catch (QueryException $e) {
            throw BillingException::fromDatabase($e) ?? $e;
        }
    }

    public function quote(string $tenantId, string $kind, string $ref, ?string $interval, string $currency, ?string $coupon): array
    {
        return (array) $this->wrap(fn () => DB::selectOne('select (billing_quote(?::char(26), ?, ?::char(26), ?, ?::char(3), ?)).*', [$tenantId, $kind, $ref, $interval, $currency, $coupon]));
    }

    public function createOrder(string $tenantId, ?string $userId, string $kind, string $ref, ?string $interval, string $currency, ?string $coupon, string $gateway): string
    {
        return trim((string) $this->wrap(fn () => DB::selectOne('select billing_create_order(?::char(26), ?, ?::char(26), ?, ?::char(3), ?, ?, ?::char(26)) as id', [$tenantId, $kind, $ref, $interval, $currency, $coupon, $gateway, $userId])->id));
    }

    public function order(string $orderId): ?OrderInfo
    {
        $r = DB::selectOne('select id, tenant_id, gateway, currency, total_minor, item_name, gateway_ref, status from orders where id = ?', [$orderId]);

        return $r === null ? null : new OrderInfo(trim($r->id), trim($r->tenant_id), $r->gateway, trim($r->currency), (int) $r->total_minor, $r->item_name, $r->gateway_ref, $r->status);
    }

    public function orderTenant(string $orderId): ?string
    {
        $t = DB::selectOne('select billing_order_tenant(?::char(26)) as t', [$orderId])->t;

        return $t === null ? null : trim($t);
    }

    public function orderByRef(string $gateway, string $ref): ?string
    {
        $o = DB::selectOne('select billing_order_by_ref(?, ?) as o', [$gateway, $ref])->o;

        return $o === null ? null : trim($o);
    }

    public function setRef(string $orderId, string $ref): void
    {
        $this->wrap(fn () => DB::select('select billing_set_gateway_ref(?::char(26), ?)', [$orderId, $ref]));
    }

    public function settle(string $orderId, string $gateway, string $txnId, int $amountMinor, string $currency, ?string $eventId, array $raw): array
    {
        $r = $this->wrap(fn () => DB::selectOne('select outcome, invoice_id from billing_settle(?::char(26), ?, ?, ?::bigint, ?, ?, ?::jsonb)',
            [$orderId, $gateway, $txnId, $amountMinor, $currency, $eventId, json_encode($raw === [] ? new \stdClass : $raw)]));

        return ['outcome' => $r->outcome, 'invoice_id' => $r->invoice_id === null ? null : trim($r->invoice_id)];
    }

    public function fail(string $orderId, string $gateway, ?string $eventId, string $outcome, array $raw): string
    {
        return (string) $this->wrap(fn () => DB::selectOne('select billing_fail_order(?::char(26), ?, ?, ?, ?::jsonb) as r',
            [$orderId, $gateway, $eventId, $outcome, json_encode($raw === [] ? new \stdClass : $raw)])->r);
    }

    public function note(string $orderId, string $gateway, ?string $eventId, array $raw): void
    {
        $this->wrap(fn () => DB::select('select billing_note_event(?::char(26), ?, ?, ?::jsonb)', [$orderId, $gateway, $eventId, json_encode($raw === [] ? new \stdClass : $raw)]));
    }

    /** Customer subscription actions: thin calls to the SQL functions, which re-check the tenant. */
    public function cancelSubscription(string $tenantId, bool $immediate): string
    {
        return (string) $this->wrap(fn () => DB::selectOne('select billing_cancel_subscription(?::char(26), ?::boolean) as r', [$tenantId, $immediate ? 'true' : 'false'])->r);
    }

    public function resumeSubscription(string $tenantId): void
    {
        $this->wrap(fn () => DB::select('select billing_resume_subscription(?::char(26))', [$tenantId]));
    }

    public function scheduleDowngrade(string $tenantId, string $planId, string $interval): void
    {
        $this->wrap(fn () => DB::select('select billing_schedule_downgrade(?::char(26), ?::char(26), ?)', [$tenantId, $planId, $interval]));
    }

    public function clearDowngrade(string $tenantId): void
    {
        $this->wrap(fn () => DB::select('select billing_clear_downgrade(?::char(26))', [$tenantId]));
    }

    /** @return array{orders_expired: int, to_grace: int, subs_expired: int, subs_cancelled: int, refills: int} */
    public function lifecycle(): array
    {
        return (array) DB::selectOne('select orders_expired, to_grace, subs_expired, subs_cancelled, refills from billing_lifecycle()');
    }
}
