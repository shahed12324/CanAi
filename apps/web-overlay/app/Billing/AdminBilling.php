<?php

declare(strict_types=1);

namespace App\Billing;

use App\Actions\Tenancy\CreateWorkspace;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;

/** Admin-only billing: analytics, coupons, refunds. Needs the `admin` connection (the refund functions check the DB login is an admin). */
final class AdminBilling
{
    public function __construct(private readonly Connection $db) {}

    /** @return list<object> paid orders per month and currency; refunds are shown separately so nothing is hidden */
    public function revenueByMonth(int $months = 12): array
    {
        return $this->db->select("select to_char(date_trunc('month', paid_at at time zone 'Asia/Dhaka'), 'YYYY-MM') as month, currency, count(*) as orders, sum(total_minor) as gross_minor, sum(vat_minor) as vat_minor, sum(refunded_minor) as refunded_minor
            from orders where status in ('paid', 'partially_refunded', 'refunded')
              and paid_at >= (date_trunc('month', now() at time zone 'Asia/Dhaka') - make_interval(months => ?::int)) at time zone 'Asia/Dhaka'
            group by 1, 2 order by 1 desc, 2", [max(1, min(36, $months))]);
    }

    /** Monthly recurring revenue in BDT paisa: every live paid subscription at its monthly-equivalent catalog price. */
    public function mrr(): object
    {
        return $this->db->selectOne("select coalesce(sum(round(pp.amount * 100 / case s.interval when 'year' then 12 else 1 end)), 0)::bigint as mrr_minor, count(*) as subscribers
            from subscriptions s join plan_prices pp on pp.plan_id = s.plan_id and pp.currency = 'BDT' and pp.interval = s.interval
            where s.status in ('active', 'trialing')");
    }

    /** @return list<object> */
    public function subscribersByPlan(): array
    {
        return $this->db->select("select p.name, s.status, count(*) as n from subscriptions s join plans p on p.id = s.plan_id
            where s.status in ('active', 'trialing', 'grace', 'past_due') group by p.name, p.sort, s.status order by p.sort, s.status");
    }

    public function churn(): object
    {
        return $this->db->selectOne("select count(*) filter (where status = 'cancelled' and cancelled_at >= now() - interval '30 days') as cancelled,
                count(*) filter (where status = 'expired' and updated_at >= now() - interval '30 days') as expired,
                count(*) filter (where status in ('active', 'trialing', 'grace', 'past_due')) as live
            from subscriptions");
    }

    /** @return list<object> last 30 days per gateway */
    public function gatewayStats(): array
    {
        return $this->db->select("select gateway, count(*) as orders,
                count(*) filter (where status in ('paid', 'partially_refunded', 'refunded')) as paid,
                count(*) filter (where status in ('failed', 'cancelled')) as failed,
                count(*) filter (where status = 'review') as review,
                coalesce(sum(total_minor) filter (where status in ('paid', 'partially_refunded', 'refunded')), 0)::bigint as gross_minor
            from orders where created_at >= now() - interval '30 days' group by gateway order by gateway");
    }

    /** @return list<object> */
    public function orders(string $status = ''): array
    {
        return $this->db->select("select o.id, t.name as tenant, o.item_name, o.currency, o.total_minor, o.refunded_minor, o.gateway, o.status, o.created_at, o.paid_at
            from orders o join tenants t on t.id = o.tenant_id where (? = '' or o.status = ?) order by o.created_at desc limit 50", [$status, $status]);
    }

    /** @return list<object> orders waiting for a human: wrong amount / currency / risk */
    public function reviewQueue(): array
    {
        return $this->db->select("select o.id, t.name as tenant, o.item_name, o.currency, o.total_minor, o.gateway, o.created_at,
                (select e.amount_minor from payment_events e where e.order_id = o.id and e.outcome in ('amount_mismatch', 'currency_mismatch') order by e.id desc limit 1) as received_minor,
                (select e.currency from payment_events e where e.order_id = o.id and e.outcome in ('amount_mismatch', 'currency_mismatch') order by e.id desc limit 1) as received_currency
            from orders o join tenants t on t.id = o.tenant_id where o.status = 'review' order by o.created_at");
    }

    /** @return list<object> */
    public function recentRefunds(): array
    {
        return $this->db->select("select r.id, r.order_id, t.name as tenant, r.amount_minor, r.currency, r.reason, r.credits_clawed_back, r.subscription_cancelled, r.created_at
            from refunds r join tenants t on t.id = r.tenant_id order by r.created_at desc limit 20");
    }

    /** @return list<object> */
    public function coupons(): array
    {
        return $this->db->select("select id, code, description, kind, percent_bp, fixed_minor, currency, applies_to, max_redemptions, per_tenant_limit, redeemed_count, valid_from, valid_until, status
            from coupons order by created_at desc limit 100");
    }

    /** @param array{code: string, description: ?string, kind: string, percent_bp: ?int, fixed_minor: ?int, currency: ?string, applies_to: string, max_redemptions: ?int, per_tenant_limit: int, valid_from: ?string, valid_until: ?string} $c */
    public function createCoupon(array $c): string
    {
        $id = CreateWorkspace::newId();
        $this->db->insert("insert into coupons (id, code, description, kind, percent_bp, fixed_minor, currency, applies_to, max_redemptions, per_tenant_limit, valid_from, valid_until)
            values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)", [$id, strtoupper($c['code']), $c['description'], $c['kind'], $c['percent_bp'], $c['fixed_minor'], $c['currency'], $c['applies_to'], $c['max_redemptions'], $c['per_tenant_limit'], $c['valid_from'], $c['valid_until']]);

        return $id;
    }

    public function setCouponStatus(string $id, string $status): void
    {
        $this->db->update("update coupons set status = ?, updated_at = now() where id = ?", [$status, $id]);
    }

    public function refund(string $orderId, int $amountMinor, string $reason, ?string $gatewayRef, bool $clawBack, bool $cancelSubscription, string $actorId): string
    {
        try {
            return trim((string) $this->db->selectOne("select billing_record_refund(?::char(26), ?::bigint, ?, ?, ?::boolean, ?::boolean, ?::char(26)) as id",
                [$orderId, $amountMinor, $reason, $gatewayRef, $clawBack ? 'true' : 'false', $cancelSubscription ? 'true' : 'false', $actorId])->id);
        } catch (QueryException $e) {
            throw BillingException::fromDatabase($e) ?? $e;
        }
    }

    public function closeReview(string $orderId, string $actorId): void
    {
        try {
            $this->db->select("select billing_close_review(?::char(26), ?::char(26))", [$orderId, $actorId]);
        } catch (QueryException $e) {
            throw BillingException::fromDatabase($e) ?? $e;
        }
    }

    /** @return array{vat_bp: int, grace_days: int, order_ttl_minutes: int, seller: array<string, string>} */
    public function settings(): array
    {
        $rows = [];
        foreach ($this->db->select("select key, value from system_settings where key like 'billing.%'") as $r) {
            $rows[$r->key] = json_decode($r->value, true);
        }

        return ['vat_bp' => (int) ($rows['billing.vat_bp'] ?? 1500), 'grace_days' => (int) ($rows['billing.grace_days'] ?? 7), 'order_ttl_minutes' => (int) ($rows['billing.order_ttl_minutes'] ?? 120),
            'seller' => array_map('strval', (array) ($rows['billing.seller'] ?? []))];
    }

    public function saveSetting(string $key, mixed $value, string $actorId): void
    {
        $this->db->statement("insert into system_settings (key, value, updated_by) values (?, ?::jsonb, ?) on conflict (key) do update set value = excluded.value, updated_by = excluded.updated_by, updated_at = now()",
            [$key, json_encode($value, JSON_UNESCAPED_UNICODE), $actorId]);
    }
}
