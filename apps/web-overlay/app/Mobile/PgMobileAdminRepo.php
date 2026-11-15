<?php

declare(strict_types=1);

namespace App\Mobile;

use App\Actions\Tenancy\CreateWorkspace;
use Illuminate\Support\Facades\DB;

final class PgMobileAdminRepo implements MobileAdminRepo
{
    public function tenantsWithPendingApprovals(int $limit): array
    {
        $out = [];
        foreach (DB::connection('admin')->select("select tenant_id, count(*) as n from outreach_messages where status = 'pending_approval' group by tenant_id order by min(created_at) limit ?", [$limit]) as $r) {
            $out[] = ['tenant' => trim($r->tenant_id), 'pending' => (int) $r->n];
        }

        return $out;
    }

    public function deviceUsers(string $tenant): array
    {
        return array_map(fn ($r) => trim($r->user_id), DB::connection('admin')->select("select distinct user_id from mobile_devices where tenant_id = ? and revoked_at is null", [$tenant]));
    }

    public function hasUnread(string $tenant, string $user, string $kind): bool
    {
        return DB::connection('admin')->selectOne("select 1 as x from inbox_notifications where tenant_id = ? and user_id = ? and kind = ? and read_at is null limit 1", [$tenant, $user, $kind]) !== null;
    }

    public function insertNotification(string $tenant, string $user, string $kind, string $title, string $body, ?string $refKind): string
    {
        $id = CreateWorkspace::newId();
        DB::connection('admin')->insert("insert into inbox_notifications (id, tenant_id, user_id, kind, title, body, ref_kind) values (?, ?, ?, ?, ?, ?, ?)", [$id, $tenant, $user, $kind, mb_substr($title, 0, 160), mb_substr($body, 0, 500), $refKind]);

        return $id;
    }

    public function unpushed(int $limit): array
    {
        $out = [];
        foreach (DB::connection('admin')->select("select n.id, n.tenant_id, n.kind, n.title, n.body, n.ref_kind, n.ref_id, d.push_token from inbox_notifications n
            join mobile_devices d on d.tenant_id = n.tenant_id and d.user_id = n.user_id and d.revoked_at is null and d.push_token is not null
            where n.pushed_at is null and n.push_error is null and n.created_at > now() - interval '1 day' order by n.created_at limit ?", [$limit]) as $r) {
            $out[] = ['id' => trim($r->id), 'tenant' => trim($r->tenant_id), 'token' => $r->push_token, 'title' => $r->title, 'body' => $r->body, 'kind' => $r->kind, 'ref_kind' => $r->ref_kind, 'ref_id' => $r->ref_id];
        }

        return $out;
    }

    public function markPushed(string $id, ?string $error): void
    {
        DB::connection('admin')->update("update inbox_notifications set pushed_at = case when ?::text is null then now() else pushed_at end, push_error = ?::text where id = ?", [$error, $error === null ? null : substr($error, 0, 80), $id]);
    }

    public function dropPushToken(string $token): void
    {
        DB::connection('admin')->update("update mobile_devices set push_token = null where push_token = ?", [$token]);
    }

    public function retireDeadDevices(): int
    {
        return DB::connection('admin')->affectingStatement("update mobile_devices d set revoked_at = now(), push_token = null where d.revoked_at is null
            and not exists (select 1 from personal_access_tokens t where t.id = d.token_id and (t.expires_at is null or t.expires_at > now()))");
    }
}
