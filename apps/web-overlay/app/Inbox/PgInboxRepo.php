<?php

declare(strict_types=1);

namespace App\Inbox;

use App\Actions\Tenancy\CreateWorkspace;
use App\Catalog\PgCatalogRepo;
use Illuminate\Support\Facades\DB;

final class PgInboxRepo implements InboxRepo
{
    /** @return array<string, mixed> */
    private static function trimIds(object $r): array
    {
        $a = (array) $r;
        foreach (['id', 'tenant_id', 'channel_id', 'contact_id', 'assigned_to', 'conversation_id'] as $k) {
            if (isset($a[$k])) {
                $a[$k] = trim((string) $a[$k]);
            }
        }
        if (array_key_exists('allowed_origins', $a)) {
            $a['allowed_origins'] = PgCatalogRepo::pgArray((string) $a['allowed_origins']);
        }

        return $a;
    }

    public function channelByKey(string $key): ?array
    {
        $r = DB::selectOne('select id, tenant_id, kind, status, allowed_origins, greeting, name, external_ref from inbox_channel_by_key(?)', [$key]);

        return $r === null ? null : self::trimIds($r);
    }

    public function channels(): array
    {
        return array_map([self::class, 'trimIds'], DB::select('select id, tenant_id, kind, name, status, public_key, allowed_origins, greeting, agent_mode, created_at from inbox_channels order by created_at'));
    }

    public function createChannel(string $tenantId, string $kind, string $name, array $origins, ?string $greeting): array
    {
        $id = CreateWorkspace::newId();
        $key = bin2hex(random_bytes(16));
        DB::insert('insert into inbox_channels (id, tenant_id, kind, name, public_key, allowed_origins, greeting) values (?, ?, ?, ?, ?, ?::text[], ?)',
            [$id, $tenantId, $kind, $name, $key, PgCatalogRepo::toPgArray($origins), $greeting]);

        return ['id' => $id, 'public_key' => $key];
    }

    public function updateChannel(string $id, string $name, string $status, array $origins, ?string $greeting): void
    {
        DB::update('update inbox_channels set name = ?, status = ?, allowed_origins = ?::text[], greeting = ? where id = ?', [$name, $status, PgCatalogRepo::toPgArray($origins), $greeting, $id]);
    }

    public function contactEnsure(string $channelId, string $externalId, ?string $name, ?string $tokenHash): array
    {
        $r = DB::selectOne('select result, contact_id, conversation_id from inbox_contact_ensure(?, ?, ?, ?, ?, ?)', [$channelId, $externalId, $name, $tokenHash, CreateWorkspace::newId(), CreateWorkspace::newId()]);

        return ['result' => $r->result, 'contact_id' => $r->contact_id === null ? null : trim($r->contact_id), 'conversation_id' => $r->conversation_id === null ? null : trim($r->conversation_id)];
    }

    public function contactVerify(string $channelId, string $externalId, string $tokenHash): ?string
    {
        $r = DB::selectOne('select inbox_contact_verify(?, ?, ?) as v', [$channelId, $externalId, $tokenHash]);

        return $r->v === null ? null : trim($r->v);
    }

    public function receive(string $conversationId, string $body, ?string $externalId): array
    {
        $r = DB::selectOne('select result, seq from inbox_receive(?, ?, ?)', [$conversationId, $body, $externalId]);

        return ['result' => $r->result, 'seq' => $r->seq === null ? null : (int) $r->seq];
    }

    public function send(string $conversationId, ?string $userId, string $sender, string $body): array
    {
        $r = DB::selectOne('select result, seq from inbox_send(?, ?, ?, ?)', [$conversationId, $userId, $sender, $body]);

        return ['result' => $r->result, 'seq' => $r->seq === null ? null : (int) $r->seq];
    }

    public function setMode(string $conversationId, ?string $userId, string $mode, ?string $reason): void
    {
        DB::select('select inbox_set_mode(?, ?, ?, ?)', [$conversationId, $userId, $mode, $reason]);
    }

    public function resolve(string $conversationId, ?string $userId, bool $resolved): void
    {
        DB::select('select inbox_resolve(?, ?, ?)', [$conversationId, $userId, $resolved ? 'true' : 'false']);
    }

    public function markRead(string $conversationId): void
    {
        DB::select('select inbox_mark_read(?)', [$conversationId]);
    }

    public function conversations(string $filter, ?string $userId, int $limit = 100): array
    {
        $where = match ($filter) {
            'resolved' => "v.status = 'resolved'",
            'human' => "v.status = 'open' and v.mode = 'human'",
            'unread' => 'v.unread > 0',
            'mine' => "v.status = 'open' and v.assigned_to = ?",
            default => "v.status = 'open'",
        };
        $args = $filter === 'mine' ? [$userId, min(200, max(1, $limit))] : [min(200, max(1, $limit))];
        $rows = DB::select("select v.id, v.channel_id, v.status, v.mode, v.handoff_reason, v.assigned_to, v.unread, v.preview, v.last_message_at, v.last_inbound_at, c.display_name, c.external_id, h.kind, h.name as channel_name, h.agent_mode
            from inbox_conversations v join inbox_contacts c on c.id = v.contact_id join inbox_channels h on h.id = v.channel_id where $where order by v.last_message_at desc limit ?", $args);

        return array_map([self::class, 'trimIds'], $rows);
    }

    public function conversation(string $id): ?array
    {
        $r = DB::selectOne('select v.id, v.channel_id, v.status, v.mode, v.handoff_reason, v.assigned_to, v.unread, v.preview, v.last_message_at, v.last_inbound_at, c.display_name, c.external_id, h.kind, h.name as channel_name
            from inbox_conversations v join inbox_contacts c on c.id = v.contact_id join inbox_channels h on h.id = v.channel_id where v.id = ?', [$id]);

        return $r === null ? null : self::trimIds($r);
    }

    public function messages(string $conversationId, int $afterSeq = 0, int $limit = 200, bool $outboundOnly = false): array
    {
        $rows = DB::select("select seq, direction, sender, user_id, body, status, error, created_at from inbox_messages where conversation_id = ? and seq > ? and (direction = 'out' or ? = 'n') order by seq limit ?",
            [$conversationId, $afterSeq, $outboundOnly ? 'y' : 'n', min(500, max(1, $limit))]);

        return array_map(fn ($r) => (array) $r, $rows);
    }

    public function credentials(string $channelId): ?array
    {
        $r = DB::selectOne('select credentials_ct, key_version, kind, external_ref from inbox_credentials(?)', [$channelId]);

        return $r === null || $r->credentials_ct === null ? null : ['cipher' => $r->credentials_ct, 'version' => (int) $r->key_version, 'kind' => $r->kind, 'external_ref' => (string) $r->external_ref];
    }

    public function createExternalChannel(string $tenantId, string $kind, string $name, string $accountRef, array $sealed, string $publicKey): array
    {
        $id = CreateWorkspace::newId();
        $key = $publicKey;
        DB::insert('insert into inbox_channels (id, tenant_id, kind, name, public_key, external_ref, credentials_ct, key_version) values (?, ?, ?, ?, ?, ?, ?, ?)',
            [$id, $tenantId, $kind, $name, $key, $accountRef, $sealed['cipher'], $sealed['version']]);

        return ['id' => $id, 'public_key' => $key];
    }

    public function dispatchContext(int $seq): ?array
    {
        $r = DB::selectOne('select seq, channel_id, kind, external_ref, to_external, body, status, attempts, window_ok, emergency, channel_active from inbox_dispatch_ctx(?)', [$seq]);
        if ($r === null) {
            return null;
        }
        $a = (array) $r;
        $a['channel_id'] = trim((string) $a['channel_id']);
        foreach (['window_ok', 'emergency', 'channel_active'] as $k) {
            $a[$k] = $a[$k] === true || $a[$k] === 't' || $a[$k] === 1 || $a[$k] === '1';
        }

        return $a;
    }

    public function messageStatus(int $seq, string $status, ?string $error): bool
    {
        $r = DB::selectOne('select inbox_message_status(?, ?, ?) as ok', [$seq, $status, $error]);

        return $r->ok === true || $r->ok === 't' || $r->ok === 1;
    }

    public function messageRetry(int $seq, string $error): string
    {
        return (string) DB::selectOne('select inbox_message_retry(?, ?) as r', [$seq, $error])->r;
    }
}
