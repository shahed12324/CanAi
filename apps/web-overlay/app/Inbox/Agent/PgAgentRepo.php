<?php

declare(strict_types=1);

namespace App\Inbox\Agent;

use Illuminate\Support\Facades\DB;

final class PgAgentRepo implements AgentRepo
{
    public function settings(): array
    {
        $r = DB::selectOne('select instructions, business_info from inbox_agent_settings');

        return $r === null ? ['instructions' => '', 'business_info' => ''] : ['instructions' => (string) $r->instructions, 'business_info' => (string) $r->business_info];
    }

    public function saveSettings(string $tenantId, string $instructions, string $businessInfo): void
    {
        DB::insert('insert into inbox_agent_settings (tenant_id, instructions, business_info) values (?, ?, ?) on conflict (tenant_id) do update set instructions = excluded.instructions, business_info = excluded.business_info, updated_at = now()',
            [$tenantId, $instructions, $businessInfo]);
    }

    public function setChannelMode(string $channelId, string $mode): void
    {
        DB::update('update inbox_channels set agent_mode = ? where id = ?', [$mode, $channelId]);
    }

    public function search(array $keywords, int $limit = 5): array
    {
        $pat = array_map(fn ($k) => '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $k).'%', array_slice($keywords, 0, 6));
        while (count($pat) < 6) {
            $pat[] = "%\x01nomatch\x01%";                              // padding that can never match
        }
        $limit = min(10, max(1, $limit));
        $sql = "select p.id, p.live, (select coalesce(jsonb_object_agg(v.id, (not v.track_stock or v.stock_qty > 0)), '{}'::jsonb) from product_variants v where v.product_id = p.id and v.archived_at is null) as in_stock,
            (case when h.hay ilike ? then 1 else 0 end) + (case when h.hay ilike ? then 1 else 0 end) + (case when h.hay ilike ? then 1 else 0 end) + (case when h.hay ilike ? then 1 else 0 end) + (case when h.hay ilike ? then 1 else 0 end) + (case when h.hay ilike ? then 1 else 0 end) as score
            from products p cross join lateral (select coalesce(p.live->>'title', '') || ' ' || coalesce(p.live->>'category', '') || ' ' || coalesce(p.live->>'sku', '') || ' ' || coalesce(p.live->>'description', '') || ' ' || coalesce(array_to_string(p.tags, ' '), '') as hay) h
            where p.live is not null and p.deleted_at is null order by score desc, p.published_at desc limit ?";
        $rows = DB::select($sql, [...$pat, $limit]);
        $matched = $rows !== [] && (int) $rows[0]->score > 0;
        $products = [];
        foreach ($rows as $r) {
            if ($matched && (int) $r->score === 0) {
                continue;
            }
            $products[] = ['id' => trim((string) $r->id), 'live' => json_decode((string) $r->live, true) ?: [], 'in_stock' => json_decode((string) $r->in_stock, true) ?: []];
        }
        $cats = array_map(fn ($r) => (string) $r->c, DB::select("select distinct p.live->>'category' as c from products p where p.live is not null and p.deleted_at is null and p.live->>'category' is not null order by 1 limit 15"));

        return ['products' => $products, 'matched' => $matched, 'categories' => $cats];
    }

    public function byVariants(array $variantIds): array
    {
        $ids = array_values(array_unique(array_filter($variantIds, fn ($v) => is_string($v) && preg_match('/\A[0-9A-Za-z]{26}\z/', $v) === 1)));
        if ($ids === []) {
            return [];
        }
        $rows = DB::select("select p.id, p.live, (select coalesce(jsonb_object_agg(v.id, (not v.track_stock or v.stock_qty > 0)), '{}'::jsonb) from product_variants v where v.product_id = p.id and v.archived_at is null) as in_stock
            from products p where p.live is not null and p.deleted_at is null and exists (select 1 from product_variants w where w.product_id = p.id and w.id = any(?::char(26)[])) limit 10", ['{'.implode(',', $ids).'}']);

        return array_map(fn ($r) => ['id' => trim((string) $r->id), 'live' => json_decode((string) $r->live, true) ?: [], 'in_stock' => json_decode((string) $r->in_stock, true) ?: []], $rows);
    }

    public function related(?string $category, array $excludeProductIds, int $limit = 1): array
    {
        if ($category === null || trim($category) === '') {
            return [];
        }
        $ex = array_values(array_filter($excludeProductIds, fn ($v) => is_string($v) && preg_match('/\A[0-9A-Za-z]{26}\z/', $v) === 1));
        $rows = DB::select("select p.id, p.live, (select coalesce(jsonb_object_agg(v.id, (not v.track_stock or v.stock_qty > 0)), '{}'::jsonb) from product_variants v where v.product_id = p.id and v.archived_at is null) as in_stock
            from products p where p.live is not null and p.deleted_at is null and p.live->>'category' = ? and not (p.id = any(?::char(26)[]))
            and exists (select 1 from product_variants w where w.product_id = p.id and w.archived_at is null and (not w.track_stock or w.stock_qty > 0)) order by p.published_at desc nulls last limit ?",
            [$category, '{'.implode(',', $ex).'}', min(3, max(1, $limit))]);

        return array_map(fn ($r) => ['id' => trim((string) $r->id), 'live' => json_decode((string) $r->live, true) ?: [], 'in_stock' => json_decode((string) $r->in_stock, true) ?: []], $rows);
    }

    public function saveDraft(string $tenantId, string $conversationId, string $body, int $inSeq): void
    {
        DB::insert('insert into inbox_agent_drafts (conversation_id, tenant_id, body, in_seq) values (?, ?, ?, ?) on conflict (conversation_id) do update set body = excluded.body, in_seq = excluded.in_seq, created_at = now()',
            [$conversationId, $tenantId, mb_substr($body, 0, 1500), $inSeq]);
    }

    public function draft(string $conversationId): ?array
    {
        $r = DB::selectOne('select body, in_seq from inbox_agent_drafts where conversation_id = ?', [$conversationId]);

        return $r === null ? null : ['body' => (string) $r->body, 'in_seq' => (int) $r->in_seq];
    }

    public function deleteDraft(string $conversationId): void
    {
        DB::delete('delete from inbox_agent_drafts where conversation_id = ?', [$conversationId]);
    }
}
