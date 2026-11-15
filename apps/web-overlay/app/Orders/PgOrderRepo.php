<?php

declare(strict_types=1);

namespace App\Orders;

use App\Actions\Tenancy\CreateWorkspace;
use App\Catalog\PgCatalogRepo;
use Illuminate\Support\Facades\DB;

final class PgOrderRepo implements OrderRepo
{
    public function create(string $tenantId, ?string $userId, string $source, ?string $conversationId, string $name, string $phone, string $address, string $zone, ?string $note, array $items, float $discountPct): array
    {
        $id = CreateWorkspace::newId();
        $r = DB::selectOne('select result, order_id, number, total_minor, risk_score, needs_review from order_create(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?::jsonb, ?::numeric)',
            [$id, $tenantId, $userId, $source, $conversationId, $name, $phone, $address, $zone, $note, json_encode($items, JSON_UNESCAPED_UNICODE), $discountPct]);

        return ['result' => (string) $r->result, 'order_id' => $r->order_id === null ? null : trim((string) $r->order_id), 'number' => $r->number === null ? null : (int) $r->number,
            'total_minor' => $r->total_minor === null ? null : (int) $r->total_minor, 'risk_score' => $r->risk_score === null ? null : (int) $r->risk_score, 'needs_review' => $r->needs_review === null ? null : (bool) $r->needs_review];
    }

    public function transition(string $orderId, string $userId, string $to, ?string $note): string
    {
        return (string) DB::selectOne('select order_transition(?, ?, ?, ?) as r', [$orderId, $userId, $to, $note])->r;
    }

    public function orders(?string $status, int $limit = 100): array
    {
        $rows = DB::select("select id, number, status, customer_name, phone, total_minor, currency, risk_score, needs_review, source, created_at from store_orders where (? = 'all' or status = ?) order by created_at desc limit ?",
            [$status ?? 'all', $status ?? 'all', min(300, max(1, $limit))]);

        return array_map(fn ($r) => ['id' => trim((string) $r->id)] + (array) $r, $rows);
    }

    public function order(string $id): ?array
    {
        $o = DB::selectOne('select id, number, conversation_id, source, status, payment_method, customer_name, phone, address, zone, note, currency, subtotal_minor, discount_pct, discount_minor, delivery_minor, total_minor, risk_score, risk_reasons, needs_review, created_by, confirmed_by, confirmed_at, cancel_reason, created_at from store_orders where id = ?', [$id]);
        if ($o === null) {
            return null;
        }
        $o = (array) $o;
        $o['id'] = trim((string) $o['id']);
        $o['conversation_id'] = $o['conversation_id'] === null ? null : trim((string) $o['conversation_id']);
        $o['risk_reasons'] = PgCatalogRepo::pgArray((string) $o['risk_reasons']);
        $items = array_map(fn ($r) => (array) $r, DB::select('select product_id, variant_id, title, unit_price_minor, qty, line_total_minor from store_order_items where order_id = ? order by seq', [$id]));
        $events = array_map(fn ($r) => (array) $r, DB::select('select from_status, to_status, user_id, note, created_at from store_order_events where order_id = ? order by seq', [$id]));

        return ['order' => $o, 'items' => $items, 'events' => $events];
    }

    public function sellable(): array
    {
        $rows = DB::select("select v.id, p.live->>'title' as title, v.options, coalesce(nullif(x.val->>'price_minor', '')::bigint, (p.live->>'price_minor')::bigint) as price_minor, p.live->>'currency' as currency, v.stock_qty, v.track_stock
            from products p join product_variants v on v.product_id = p.id and v.tenant_id = p.tenant_id and v.archived_at is null
            cross join lateral jsonb_array_elements(coalesce(p.live->'variants', '[]'::jsonb)) x(val)
            where p.live is not null and p.deleted_at is null and x.val->>'id' = v.id order by p.live->>'title', v.created_at limit 300");

        return array_map(function ($r) {
            $o = json_decode((string) $r->options, true);

            return ['id' => trim((string) $r->id), 'title' => (string) $r->title, 'options' => is_array($o) ? $o : [], 'price_minor' => (int) $r->price_minor,
                'currency' => (string) ($r->currency ?: 'BDT'), 'stock_qty' => (int) $r->stock_qty, 'track_stock' => (bool) $r->track_stock];
        }, $rows);
    }

    public function settings(): array
    {
        $r = DB::selectOne('select max_discount_pct, delivery_inside_minor, delivery_outside_minor, review_score, chat_orders from order_settings');

        return $r === null ? ['max_discount_pct' => 0, 'delivery_inside_minor' => 6000, 'delivery_outside_minor' => 12000, 'review_score' => 50, 'chat_orders' => false]
            : ['max_discount_pct' => (int) $r->max_discount_pct, 'delivery_inside_minor' => (int) $r->delivery_inside_minor, 'delivery_outside_minor' => (int) $r->delivery_outside_minor, 'review_score' => (int) $r->review_score, 'chat_orders' => (bool) $r->chat_orders];
    }

    public function saveSettings(string $tenantId, int $maxDiscountPct, int $deliveryInside, int $deliveryOutside, int $reviewScore, bool $chatOrders): void
    {
        DB::insert('insert into order_settings (tenant_id, max_discount_pct, delivery_inside_minor, delivery_outside_minor, review_score, chat_orders) values (?, ?, ?, ?, ?, ?) on conflict (tenant_id) do update set max_discount_pct = excluded.max_discount_pct, delivery_inside_minor = excluded.delivery_inside_minor, delivery_outside_minor = excluded.delivery_outside_minor, review_score = excluded.review_score, chat_orders = excluded.chat_orders, updated_at = now()',
            [$tenantId, $maxDiscountPct, $deliveryInside, $deliveryOutside, $reviewScore, $chatOrders ? 'true' : 'false']);
    }
}
