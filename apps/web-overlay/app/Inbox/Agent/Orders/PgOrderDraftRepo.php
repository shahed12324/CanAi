<?php

declare(strict_types=1);

namespace App\Inbox\Agent\Orders;

use Illuminate\Support\Facades\DB;

final class PgOrderDraftRepo implements OrderDraftRepo
{
    public function get(string $conversationId): ?OrderDraft
    {
        $r = DB::selectOne("select state, items, customer_name, phone, address, zone, summary_hash, upsold, turns from inbox_order_drafts where conversation_id = ? and updated_at > now() - interval '24 hours'", [$conversationId]);
        if ($r === null) {
            return null;
        }
        $items = json_decode((string) $r->items, true);

        return new OrderDraft(is_array($items) ? array_values($items) : [], $r->customer_name, $r->phone, $r->address, $r->zone, (string) $r->state, $r->summary_hash === null ? null : trim((string) $r->summary_hash), (bool) $r->upsold, (int) $r->turns);
    }

    public function save(string $tenantId, string $conversationId, OrderDraft $d): void
    {
        DB::insert('insert into inbox_order_drafts (conversation_id, tenant_id, state, items, customer_name, phone, address, zone, summary_hash, upsold, turns) values (?, ?, ?, ?::jsonb, ?, ?, ?, ?, ?, ?, ?)
            on conflict (conversation_id) do update set state = excluded.state, items = excluded.items, customer_name = excluded.customer_name, phone = excluded.phone, address = excluded.address, zone = excluded.zone, summary_hash = excluded.summary_hash, upsold = excluded.upsold, turns = excluded.turns, updated_at = now()',
            [$conversationId, $tenantId, $d->state, json_encode($d->items), $d->name, $d->phone, $d->address, $d->zone, $d->hash, $d->upsold ? 'true' : 'false', min(100, $d->turns)]);
    }

    public function delete(string $conversationId): void
    {
        DB::delete('delete from inbox_order_drafts where conversation_id = ?', [$conversationId]);
    }
}
