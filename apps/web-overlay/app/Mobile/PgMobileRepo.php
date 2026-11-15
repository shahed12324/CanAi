<?php

declare(strict_types=1);

namespace App\Mobile;

use App\Actions\Tenancy\CreateWorkspace;
use Illuminate\Support\Facades\DB;

/** Raw SQL on purpose: every statement runs under the tenant's RLS and is executed against real Postgres in tests/db. */
final class PgMobileRepo implements MobileRepo
{
    public function registerDevice(string $tenant, string $user, int $tokenId, string $name, string $platform, ?string $pushToken, ?string $appVersion): string
    {
        $id = CreateWorkspace::newId();
        $r = DB::selectOne("insert into mobile_devices (id, tenant_id, user_id, token_id, name, platform, push_token, app_version, last_seen_at) values (?, ?, ?, ?, ?, ?, ?, ?, now())
            on conflict (tenant_id, user_id, token_id) do update set name = excluded.name, push_token = excluded.push_token, app_version = excluded.app_version, last_seen_at = now(), revoked_at = null
            returning id", [$id, $tenant, $user, $tokenId, mb_substr($name, 0, 100), $platform, $pushToken, $appVersion]);

        return trim($r->id);
    }

    public function device(string $tenant, string $user, int $tokenId): ?array
    {
        $r = DB::selectOne("select id, pin_hash, pin_locked_until::text as locked_until, push_token from mobile_devices where tenant_id = ? and user_id = ? and token_id = ? and revoked_at is null", [$tenant, $user, $tokenId]);

        return $r === null ? null : ['id' => trim($r->id), 'has_pin' => $r->pin_hash !== null, 'pin_hash' => $r->pin_hash, 'locked_until' => $r->locked_until, 'push_token' => $r->push_token];
    }

    public function setPushToken(string $deviceId, ?string $token): void
    {
        DB::update("update mobile_devices set push_token = ?, last_seen_at = now() where id = ? and revoked_at is null", [$token, $deviceId]);
    }

    public function revokeDevice(string $deviceId): void
    {
        DB::update("update mobile_devices set revoked_at = now(), push_token = null where id = ? and revoked_at is null", [$deviceId]);
    }

    public function revokeToken(string $user, int $tokenId): int
    {
        return (int) DB::selectOne("select mobile_revoke_token(?, ?) as n", [$user, $tokenId])->n;
    }

    public function setPin(string $deviceId, string $user, string $hash): void
    {
        DB::select("select mobile_set_pin(?, ?, ?)", [$deviceId, $user, $hash]);
    }

    public function pinAttempt(string $deviceId, string $user, bool $ok): string
    {
        return (string) DB::selectOne("select mobile_pin_attempt(?, ?, ?::boolean) as r", [$deviceId, $user, $ok ? 'true' : 'false'])->r;
    }

    public function stepUpActive(string $deviceId, string $user): bool
    {
        return (bool) DB::selectOne("select mobile_stepup_active(?, ?) as r", [$deviceId, $user])->r;
    }

    public function approvals(int $limit): array
    {
        $out = [];
        foreach (DB::select("select kind, ref, title, summary, created_at::text as created_at from mobile_approvals(?)", [$limit]) as $r) {
            $out[] = ['kind' => $r->kind, 'ref' => trim($r->ref), 'title' => $r->title, 'summary' => $r->summary, 'created_at' => $r->created_at];
        }

        return $out;
    }

    public function decide(string $deviceId, string $user, string $kind, string $ref, string $decision): string
    {
        return (string) DB::selectOne("select mobile_decide(?, ?, ?, ?, ?) as r", [$deviceId, $user, $kind, $ref, $decision])->r;
    }

    public function emergency(string $deviceId, string $user, bool $stop, string $reason): string
    {
        return (string) DB::selectOne("select mobile_emergency(?, ?, ?::boolean, ?) as r", [$deviceId, $user, $stop ? 'true' : 'false', $reason])->r;
    }

    public function emergencyState(string $tenant): array
    {
        $r = DB::selectOne("select action, created_at::text as at, reason from emergency_events where tenant_id = ? order by seq desc limit 1", [$tenant]);

        return ['active' => $r !== null && $r->action === 'stop', 'since' => $r !== null && $r->action === 'stop' ? $r->at : null, 'reason' => $r !== null && $r->action === 'stop' ? $r->reason : null];
    }

    public function notifications(string $user, bool $onlyUnread, int $limit): array
    {
        $rows = DB::select("select id, kind, title, body, ref_kind, ref_id, created_at::text as created_at, read_at is not null as is_read from inbox_notifications
            where user_id = ? and (? = false or read_at is null) order by created_at desc limit ?", [$user, $onlyUnread ? 'true' : 'false', max(1, min($limit, 100))]);
        $out = [];
        foreach ($rows as $r) {
            $out[] = ['id' => trim($r->id), 'kind' => $r->kind, 'title' => $r->title, 'body' => $r->body, 'ref_kind' => $r->ref_kind, 'ref_id' => $r->ref_id, 'created_at' => $r->created_at, 'read' => (bool) $r->is_read];
        }

        return $out;
    }

    public function unreadCount(string $user): int
    {
        return (int) DB::selectOne("select count(*) as n from inbox_notifications where user_id = ? and read_at is null", [$user])->n;
    }

    public function markRead(string $user, ?array $ids): int
    {
        if ($ids === null) {
            return DB::affectingStatement("update inbox_notifications set read_at = now() where user_id = ? and read_at is null", [$user]);
        }
        if ($ids === []) {
            return 0;
        }

        return DB::affectingStatement("update inbox_notifications set read_at = now() where user_id = ? and read_at is null and id = any(string_to_array(?, ','))", [$user, implode(',', $ids)]);
    }

    public function notify(string $tenant, string $user, string $kind, string $title, string $body, ?string $refKind = null, ?string $refId = null): string
    {
        $id = CreateWorkspace::newId();
        DB::insert("insert into inbox_notifications (id, tenant_id, user_id, kind, title, body, ref_kind, ref_id) values (?, ?, ?, ?, ?, ?, ?, ?)",
            [$id, $tenant, $user, $kind, mb_substr($title, 0, 160), mb_substr($body, 0, 500), $refKind, $refId]);

        return $id;
    }

    public function deviceUsers(string $tenant): array
    {
        return array_map(fn ($r) => trim($r->user_id), DB::select("select distinct user_id from mobile_devices where tenant_id = ? and revoked_at is null", [$tenant]));
    }
}
