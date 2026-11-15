<?php

declare(strict_types=1);

namespace App\Messaging;

use App\Actions\Tenancy\CreateWorkspace;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/** Talks to the phase7 SQL functions on the normal app login. Tenant-scoped writes set the tenant context first (RLS). */
final class PgChannelStore implements ChannelStore
{
    public function __construct(private readonly TenantContext $tenant) {}

    public function authenticate(string $accountId, string $secret): bool
    {
        if (! TenantContext::isValidId($accountId) || $secret === '' || strlen($secret) > 200) {
            return false;
        }

        return (bool) DB::selectOne('select channel_account_authenticate(?::char(26), ?::char(64)) as ok', [$accountId, hash('sha256', $secret)])->ok;
    }

    public function claim(string $accountId, int $updateId, string $kind): string
    {
        return (string) DB::selectOne('select channel_event_claim(?::char(26), ?::bigint, ?::text) as r', [$accountId, $updateId, $kind])->r;
    }

    public function finish(string $accountId, int $updateId, string $outcome, ?string $errorCode, ?string $tenantId, ?string $linkId): void
    {
        DB::select('select channel_event_finish(?::char(26), ?::bigint, ?::text, ?::text, ?::char(26), ?::char(26))', [$accountId, $updateId, $outcome, $errorCode, $tenantId, $linkId]);
    }

    public function resolve(string $accountId, string $externalUserId): ?array
    {
        $r = DB::selectOne('select * from channel_resolve(?::char(26), ?::varchar)', [$accountId, $externalUserId]);

        return $r === null ? null : [
            'link_id' => trim($r->link_id), 'tenant_id' => trim($r->tenant_id), 'user_id' => trim($r->user_id),
            'chat_id' => (string) $r->external_chat_id, 'conversation_id' => $r->conversation_id === null ? null : trim($r->conversation_id),
        ];
    }

    public function redeem(string $accountId, string $codeHash, string $externalUserId, string $externalChatId, ?string $username): array
    {
        $r = DB::selectOne('select * from channel_link_redeem(?::char(26), ?::char(64), ?::varchar, ?::varchar, ?::varchar, ?::char(26))',
            [$accountId, $codeHash, $externalUserId, $externalChatId, $username, CreateWorkspace::newId()]);

        return ['ok' => (bool) $r->r_ok, 'reason' => (string) $r->r_reason,
            'tenant_id' => $r->r_tenant === null ? null : trim($r->r_tenant), 'user_id' => $r->r_user === null ? null : trim($r->r_user)];
    }

    public function setConversation(string $tenantId, string $linkId, ?string $conversationId): void
    {
        $this->tenant->run($tenantId, fn () => DB::table('channel_links')->where('id', $linkId)->where('tenant_id', $tenantId)
            ->update(['conversation_id' => $conversationId]));
    }

    public function touch(string $tenantId, string $linkId): void
    {
        $this->tenant->run($tenantId, fn () => DB::table('channel_links')->where('id', $linkId)->where('tenant_id', $tenantId)
            ->update(['last_seen_at' => now()]));
    }

    public function revoke(string $tenantId, string $linkId): void
    {
        $this->tenant->run($tenantId, fn () => DB::table('channel_links')->where('id', $linkId)->where('tenant_id', $tenantId)
            ->whereNull('revoked_at')->update(['revoked_at' => now()]));
    }

    public function health(string $accountId, ?string $error): void
    {
        DB::select('select channel_account_health(?::char(26), ?::text)', [$accountId, $error]);
    }
}
