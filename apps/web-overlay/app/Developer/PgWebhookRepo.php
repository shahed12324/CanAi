<?php

declare(strict_types=1);

namespace App\Developer;

use Illuminate\Support\Facades\DB;

final class PgWebhookRepo implements WebhookRepo
{
    public function create(string $tenantId, string $userId, string $id, string $name, string $url, string $cipher, int $version): string
    {
        return (string) DB::selectOne('select webhook_create(?, ?, ?, ?, ?, ?, ?) as r', [$id, $tenantId, $userId, $name, $url, $cipher, $version])->r;
    }

    public function delete(string $tenantId, string $userId, string $id): string
    {
        return (string) DB::selectOne('select webhook_delete(?, ?, ?) as r', [$id, $tenantId, $userId])->r;
    }

    public function setActive(string $tenantId, string $userId, string $id, bool $active): string
    {
        return (string) DB::selectOne('select webhook_set_active(?, ?, ?, ?) as r', [$id, $tenantId, $userId, $active])->r;
    }

    public function sendMessage(string $tenantId, string $userId, string $name, string $text): string
    {
        return (string) DB::selectOne('select webhook_send_message(?, ?, ?, ?) as r', [$tenantId, $userId, $name, $text])->r;
    }

    public function setEvents(string $tenantId, string $userId, string $id, array $events): string
    {
        $arr = '{'.implode(',', array_map(static fn (string $e): string => '"'.$e.'"', $events)).'}';

        return (string) DB::selectOne('select webhook_set_events(?, ?, ?, ?::text[]) as r', [$id, $tenantId, $userId, $arr])->r;
    }

    public function list(string $userId): array
    {
        return array_map(fn ($r) => ['id' => trim((string) $r->id), 'name' => (string) $r->name, 'url' => (string) $r->url, 'active' => (bool) $r->active, 'events' => self::pgArray((string) $r->events), 'failures' => (int) $r->failures,
            'last_ok_at' => $r->last_ok_at === null ? null : (string) $r->last_ok_at, 'last_error' => $r->last_error === null ? null : (string) $r->last_error, 'created_at' => (string) $r->created_at],
            DB::select('select id, name, url, active, events::text as events, failures, last_ok_at, last_error, created_at from webhook_endpoints where user_id = ? order by created_at desc, id desc', [$userId]));
    }

    public function deliveries(string $userId): array
    {
        return array_map(fn ($r) => ['endpoint' => (string) $r->name, 'event' => (string) $r->event, 'run_id' => trim((string) $r->run_id), 'status' => (string) $r->status, 'attempts' => (int) $r->attempts,
            'last_code' => $r->last_code === null ? null : (int) $r->last_code, 'next_at' => (string) $r->next_at, 'created_at' => (string) $r->created_at],
            DB::select('select e.name, d.event, d.run_id, d.status, d.attempts, d.last_code, d.next_at, d.created_at from webhook_deliveries d join webhook_endpoints e on e.id = d.endpoint_id and e.tenant_id = d.tenant_id
                where e.user_id = ? order by d.created_at desc, d.id desc limit 15', [$userId]));
    }

    public function enqueue(): int
    {
        return (int) DB::connection('admin')->selectOne('select webhook_enqueue() as r')->r;
    }

    public function claim(int $limit): array
    {
        return array_map(fn ($r) => ['id' => trim((string) $r->id), 'tenant_id' => trim((string) $r->tenant_id), 'url' => (string) $r->url, 'cipher' => (string) $r->secret_cipher,
            'version' => (int) $r->secret_version, 'payload' => (array) json_decode((string) $r->payload, true), 'attempts' => (int) $r->attempts],
            DB::connection('admin')->select('select id, tenant_id, url, secret_cipher, secret_version, payload::text as payload, attempts from webhook_claim(?)', [$limit]));
    }

    public function result(string $id, bool $ok, ?int $code, ?string $error): void
    {
        DB::connection('admin')->select('select webhook_result(?, ?, ?, ?)', [$id, $ok, $code, $error]);
    }

    /** @return list<string> a Postgres text[] literal such as {a,b} */
    private static function pgArray(string $v): array
    {
        $v = trim($v, '{}');

        return $v === '' ? [] : array_map(static fn (string $x): string => trim($x, '"'), explode(',', $v));
    }
}
