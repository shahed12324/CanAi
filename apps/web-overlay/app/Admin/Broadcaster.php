<?php

declare(strict_types=1);

namespace App\Admin;

use App\Actions\Tenancy\CreateWorkspace;
use Illuminate\Database\Connection;

/** Writes one announcement to the notification list of every matching shop member. Used by the Announcements page and the assistant. */
final class Broadcaster
{
    /** @return int people notified. $ref is a shop id (audience "tenant") or a plan id ("plan"); callers resolve and validate it first. */
    public static function send(Connection $db, string $title, string $body, string $audience, ?string $ref, string $actorId): int
    {
        $id = CreateWorkspace::newId();
        $n = 0;
        $db->transaction(function () use ($db, $title, $body, $audience, $ref, $id, $actorId, &$n): void {
            $members = $db->table('tenant_users as tu')->join('tenants as t', 't.id', '=', 'tu.tenant_id')->join('users as u', 'u.id', '=', 'tu.user_id')
                ->where('tu.status', 'active')->where('t.status', 'active')->whereNull('t.deleted_at')->whereNull('u.deleted_at')->where('u.status', 'active');
            if ($audience === 'tenant') {
                $members->where('tu.tenant_id', $ref);
            } elseif ($audience === 'plan') {
                $members->whereIn('tu.tenant_id', $db->table('subscriptions')->where('plan_id', $ref)->whereIn('status', ['active', 'trialing', 'past_due', 'grace'])->select('tenant_id'));
            }
            $members->select('tu.tenant_id', 'tu.user_id')->orderBy('tu.id')->chunk(500, function ($rows) use ($db, $title, $body, $id, &$n): void {
                $db->table('inbox_notifications')->insert($rows->map(fn ($r) => [
                    'id' => CreateWorkspace::newId(), 'tenant_id' => $r->tenant_id, 'user_id' => $r->user_id, 'kind' => 'announcement',
                    'title' => $title, 'body' => $body, 'ref_kind' => 'broadcast', 'ref_id' => $id,
                ])->all());
                $n += $rows->count();
            });
            $db->table('admin_broadcasts')->insert([
                'id' => $id, 'title' => $title, 'body' => $body, 'audience' => $audience,
                'audience_ref' => $audience === 'all' ? null : $ref, 'recipients' => $n, 'created_by' => $actorId,
            ]);
            AdminAudit::record('admin.broadcast.sent', 'broadcast', $id, null, ['audience' => $audience, 'recipients' => $n]);
        });

        return $n;
    }
}
