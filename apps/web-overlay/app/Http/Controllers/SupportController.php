<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Tenancy\CreateWorkspace;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** A shop's side of support: open a ticket, follow the conversation, close it. Staff answer from the admin panel. */
final class SupportController extends Controller
{
    public function index(TenantContext $tenant)
    {
        return view('support.index', [
            'tickets' => DB::table('support_tickets')->where('tenant_id', $tenant->idOrFail())
                ->orderByDesc('last_message_at')->limit(100)->get(['id', 'subject', 'category', 'priority', 'status', 'last_message_at']),
        ]);
    }

    public function store(Request $request, TenantContext $tenant)
    {
        $data = $request->validate([
            'subject' => ['required', 'string', 'min:3', 'max:200'],
            'category' => ['required', Rule::in(['billing', 'technical', 'account', 'other'])],
            'priority' => ['required', Rule::in(['low', 'normal', 'high'])],
            'body' => ['required', 'string', 'min:5', 'max:5000'],
        ]);
        $tid = $tenant->idOrFail();
        $id = CreateWorkspace::newId();
        DB::transaction(function () use ($id, $tid, $data, $request): void {
            DB::table('support_tickets')->insert([
                'id' => $id, 'tenant_id' => $tid, 'user_id' => $request->user()->getKey(), 'subject' => trim($data['subject']),
                'category' => $data['category'], 'priority' => $data['priority'],
            ]);
            DB::table('support_messages')->insert([
                'id' => CreateWorkspace::newId(), 'ticket_id' => $id, 'tenant_id' => $tid, 'author_kind' => 'shop',
                'author_id' => $request->user()->getKey(), 'body' => trim($data['body']),
            ]);
        });

        return redirect()->route('support.show', $id)->with('status', 'Ticket sent. We will reply here.');
    }

    public function show(TenantContext $tenant, string $id)
    {
        $ticket = DB::table('support_tickets')->where('tenant_id', $tenant->idOrFail())->where('id', $id)->first() ?? abort(404);

        return view('support.show', [
            'ticket' => $ticket,
            'messages' => DB::table('support_messages as m')->leftJoin('users as u', 'u.id', '=', 'm.author_id')
                ->where('m.ticket_id', $id)->orderBy('m.created_at')->get(['m.author_kind', 'm.body', 'm.created_at', 'u.name']),
        ]);
    }

    public function reply(Request $request, TenantContext $tenant, string $id)
    {
        $data = $request->validate(['body' => ['required', 'string', 'min:2', 'max:5000']]);
        $tid = $tenant->idOrFail();
        $ticket = DB::table('support_tickets')->where('tenant_id', $tid)->where('id', $id)->first() ?? abort(404);
        abort_if($ticket->status === 'closed', 422, 'This ticket is closed. Open a new one.');
        DB::transaction(function () use ($id, $tid, $data, $request): void {
            DB::table('support_messages')->insert([
                'id' => CreateWorkspace::newId(), 'ticket_id' => $id, 'tenant_id' => $tid, 'author_kind' => 'shop',
                'author_id' => $request->user()->getKey(), 'body' => trim($data['body']),
            ]);
            DB::table('support_tickets')->where('id', $id)->update(['status' => 'open', 'last_message_at' => now(), 'updated_at' => now(), 'closed_at' => null]);
        });

        return back();
    }

    public function close(TenantContext $tenant, string $id)
    {
        $n = DB::table('support_tickets')->where('tenant_id', $tenant->idOrFail())->where('id', $id)->where('status', '!=', 'closed')
            ->update(['status' => 'closed', 'closed_at' => now(), 'updated_at' => now()]);
        abort_if($n === 0, 404);

        return redirect()->route('support.index')->with('status', 'Ticket closed.');
    }
}
