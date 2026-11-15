<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\Tenancy\CreateWorkspace;
use App\Admin\AdminAudit;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Support desk for every shop's tickets (staff with users.manage). */
final class SupportAdminController extends AdminController
{
    public function index(Request $request)
    {
        $this->allow('users.manage');
        $q = $this->db()->table('support_tickets as t')->join('tenants as w', 'w.id', '=', 't.tenant_id')->join('users as u', 'u.id', '=', 't.user_id');
        $status = (string) $request->query('status', 'open');
        if (in_array($status, ['open', 'pending', 'solved', 'closed'], true)) {
            $q->where('t.status', $status);
        }
        if (in_array($p = $request->query('priority'), ['low', 'normal', 'high'], true)) {
            $q->where('t.priority', $p);
        }
        if (($term = trim((string) $request->query('q'))) !== '') {
            $like = $this->like($term);
            $q->where(fn ($w) => $w->where('t.subject', 'ilike', $like)->orWhere('w.name', 'ilike', $like)->orWhere('u.email', 'ilike', $like));
        }

        return view('admin.support.index', [
            'tickets' => $q->orderByRaw("case t.priority when 'high' then 0 when 'normal' then 1 else 2 end")->orderByDesc('t.last_message_at')
                ->paginate(25, ['t.id', 't.subject', 't.category', 't.priority', 't.status', 't.last_message_at', 'w.name as shop', 'u.email'])->withQueryString(),
            'status' => $status,
            'counts' => $this->db()->table('support_tickets')->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status'),
        ]);
    }

    public function show(string $id)
    {
        $this->allow('users.manage');
        $t = $this->db()->table('support_tickets as t')->join('tenants as w', 'w.id', '=', 't.tenant_id')->join('users as u', 'u.id', '=', 't.user_id')
            ->where('t.id', $id)->first(['t.*', 'w.name as shop', 'u.email', 'u.name as user_name']) ?? abort(404);

        return view('admin.support.show', [
            't' => $t,
            'messages' => $this->db()->table('support_messages as m')->leftJoin('users as u', 'u.id', '=', 'm.author_id')
                ->where('m.ticket_id', $id)->orderBy('m.created_at')->get(['m.author_kind', 'm.body', 'm.created_at', 'u.name']),
        ]);
    }

    public function reply(Request $request, string $id)
    {
        $this->allow('users.manage');
        $data = $request->validate(['body' => ['required', 'string', 'min:2', 'max:5000'], 'status' => ['required', Rule::in(['open', 'pending', 'solved', 'closed'])]]);
        $t = $this->db()->table('support_tickets')->where('id', $id)->first() ?? abort(404);
        $this->db()->transaction(function () use ($id, $t, $data, $request): void {
            $this->db()->table('support_messages')->insert([
                'id' => CreateWorkspace::newId(), 'ticket_id' => $id, 'tenant_id' => $t->tenant_id, 'author_kind' => 'staff',
                'author_id' => $request->user()->getKey(), 'body' => trim($data['body']),
            ]);
            $this->db()->table('support_tickets')->where('id', $id)->update([
                'status' => $data['status'], 'assigned_to' => $t->assigned_to ?? $request->user()->getKey(),
                'last_message_at' => now(), 'updated_at' => now(), 'closed_at' => $data['status'] === 'closed' ? now() : null,
            ]);
            // The shop sees it in its notification list (and on the phone, through the existing push worker).
            $this->notifyOwner($t->tenant_id, $t->user_id, 'Support replied', mb_substr('Re: '.$t->subject, 0, 160), $id);
            AdminAudit::record('admin.support.replied', 'support_ticket', $id, ['status' => $t->status], ['status' => $data['status']], $t->tenant_id);
        });

        return back()->with('status', 'Reply sent.');
    }

    public function status(Request $request, string $id)
    {
        $this->allow('users.manage');
        $data = $request->validate(['status' => ['required', Rule::in(['open', 'pending', 'solved', 'closed'])], 'priority' => ['nullable', Rule::in(['low', 'normal', 'high'])]]);
        $t = $this->db()->table('support_tickets')->where('id', $id)->first() ?? abort(404);
        $this->db()->table('support_tickets')->where('id', $id)->update(array_filter([
            'status' => $data['status'], 'priority' => $data['priority'] ?? null, 'updated_at' => now(),
            'closed_at' => $data['status'] === 'closed' ? now() : null,
        ], fn ($v, $k) => $v !== null || $k === 'closed_at', ARRAY_FILTER_USE_BOTH));
        AdminAudit::record('admin.support.status', 'support_ticket', $id, ['status' => $t->status, 'priority' => $t->priority], $data, $t->tenant_id);

        return back()->with('status', 'Ticket updated.');
    }

    private function notifyOwner(string $tenantId, string $userId, string $title, string $body, string $ticketId): void
    {
        $this->db()->table('inbox_notifications')->insert([
            'id' => CreateWorkspace::newId(), 'tenant_id' => $tenantId, 'user_id' => $userId, 'kind' => 'support_reply',
            'title' => $title, 'body' => $body, 'ref_kind' => 'support_ticket', 'ref_id' => $ticketId,
        ]);
    }
}
