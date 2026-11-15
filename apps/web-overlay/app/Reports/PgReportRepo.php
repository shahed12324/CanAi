<?php

declare(strict_types=1);

namespace App\Reports;

use Illuminate\Support\Facades\DB;

final class PgReportRepo implements ReportRepo
{
    private const META = 'select a.id as account_id, a.name as account, a.currency, a.last_synced_at, c.external_id as campaign, c.name, coalesce(sum(i.spend), 0) as spend, coalesce(sum(i.impressions), 0) as impressions,
        coalesce(sum(i.clicks), 0) as clicks, coalesce(sum(i.results), 0) as results
        from ads_accounts a join ads_campaigns c on c.account_id = a.id and c.tenant_id = a.tenant_id
        left join ads_insights_daily i on i.account_id = c.account_id and i.campaign_ext = c.external_id and i.day between ? and ?
        where (?::char(26) is null or a.client_id = ?)
        group by a.id, a.name, a.currency, a.last_synced_at, c.external_id, c.name
        having coalesce(sum(i.spend), 0) > 0 or coalesce(sum(i.impressions), 0) > 0 order by a.name, spend desc, c.name limit 400';

    private const GOOGLE = 'select a.id as account_id, a.name as account, a.currency, a.last_synced_at, c.external_id as campaign, c.name, coalesce(sum(i.cost), 0) as spend, coalesce(sum(i.impressions), 0) as impressions,
        coalesce(sum(i.clicks), 0) as clicks, coalesce(sum(i.conversions), 0) as results
        from gads_accounts a join gads_campaigns c on c.account_id = a.id and c.tenant_id = a.tenant_id
        left join gads_insights_daily i on i.account_id = c.account_id and i.campaign_ext = c.external_id and i.day between ? and ?
        where (?::char(26) is null or a.client_id = ?)
        group by a.id, a.name, a.currency, a.last_synced_at, c.external_id, c.name
        having coalesce(sum(i.cost), 0) > 0 or coalesce(sum(i.impressions), 0) > 0 order by a.name, spend desc, c.name limit 400';

    public function ads(string $platform, string $from, string $to, ?string $clientId = null): array
    {
        $rows = DB::select($platform === 'google' ? self::GOOGLE : self::META, [$from, $to, $clientId, $clientId]);

        return array_map(fn ($r) => ['account_id' => trim((string) $r->account_id), 'account' => (string) $r->account, 'currency' => trim((string) $r->currency), 'synced_at' => $r->last_synced_at === null ? null : (string) $r->last_synced_at,
            'campaign' => (string) $r->campaign, 'name' => (string) $r->name, 'spend' => (string) $r->spend, 'impressions' => (int) $r->impressions, 'clicks' => (int) $r->clicks, 'results' => (string) $r->results], $rows);
    }

    public function social(string $from, string $to, string $zone, ?string $clientId = null): array
    {
        $in = "p.status = 'posted' and (p.posted_at at time zone ?)::date between ? and ? and (?::char(26) is null or exists (select 1 from social_accounts sa where sa.id = p.account_id and sa.tenant_id = p.tenant_id and sa.client_id = ?))";
        $posts = DB::select("select p.platform, count(*) as n from social_posts p where {$in} group by p.platform order by n desc, p.platform", [$zone, $from, $to, $clientId, $clientId]);
        $e = DB::selectOne("select coalesce(sum(l.reactions), 0) as reactions, coalesce(sum(l.comments), 0) as comments, coalesce(sum(l.shares), 0) as shares, coalesce(sum(l.reach), 0) as reach
            from social_posts p join social_post_latest_stats l on l.post_id = p.id and l.tenant_id = p.tenant_id where {$in}", [$zone, $from, $to, $clientId, $clientId]);
        $t = DB::selectOne("select p.title, p.platform, l.reactions from social_posts p join social_post_latest_stats l on l.post_id = p.id and l.tenant_id = p.tenant_id where {$in} order by l.reactions desc, p.posted_at desc limit 1", [$zone, $from, $to, $clientId, $clientId]);

        return ['posts' => array_map(fn ($r) => ['platform' => (string) $r->platform, 'count' => (int) $r->n], $posts), 'reactions' => (int) ($e->reactions ?? 0), 'comments' => (int) ($e->comments ?? 0), 'shares' => (int) ($e->shares ?? 0), 'reach' => (int) ($e->reach ?? 0),
            'top' => $t === null || (int) $t->reactions <= 0 ? null : ['title' => (string) $t->title, 'platform' => (string) $t->platform, 'reactions' => (int) $t->reactions]];
    }

    public function brand(?string $clientId = null): ?array
    {
        if ($clientId !== null) {
            $own = DB::selectOne('select k.id, k.name, k.primary_color, k.accent_color, k.logo_b64, k.tone, k.email, k.phone, k.website, k.address from clients c join brand_kits k on k.id = c.brand_kit_id and k.tenant_id = c.tenant_id where c.id = ? and c.status = \'active\'', [$clientId]);
            if ($own !== null) {
                return (array) $own;
            }
        }
        $r = DB::selectOne('select id, name, primary_color, accent_color, logo_b64, tone, email, phone, website, address from brand_kits where is_default order by created_at limit 1');

        return $r === null ? null : (array) $r;
    }
}
