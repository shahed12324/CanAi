<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Tenancy\CreateWorkspace;
use App\Outreach\OutreachService;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Campaigns, the approval queue, sent history and the suppression list. */
final class OutreachController extends Controller
{
    public const STATUS_BN = ['pending_approval' => 'Awaiting approval', 'approved' => 'Allowed', 'rejected' => 'Cancel', 'sending' => 'Sending', 'sent' => 'Sent', 'failed' => 'Failed', 'bounced' => 'Bounced', 'replied' => 'Reply received', 'cancelled' => 'Closed'];

    public function __construct(private readonly OutreachService $svc, private readonly TenantContext $tenant) {}

    private static function id(string $id): string
    {
        abort_unless(preg_match('/\A[0-9a-z]{26}\z/', $id) === 1, 404);

        return $id;
    }

    public function campaigns(Request $request)
    {
        abort_unless($request->user()->can('outreach.view'), 403);

        return view('outreach.campaigns', [
            'rows' => DB::select("select c.id, c.name, c.status, c.created_at, a.from_email,
                (select count(*) from outreach_messages m where m.campaign_id = c.id and m.status = 'pending_approval') as pending,
                (select count(*) from outreach_messages m where m.campaign_id = c.id and m.sent_at is not null) as sent
                from outreach_campaigns c join email_accounts a on a.id = c.account_id order by c.created_at desc limit 100"),
            'accounts' => DB::select("select id, name, from_email from email_accounts where status = 'active' order by created_at"),
            'canManage' => $request->user()->can('outreach.manage'),
            'defaultSubject' => '{{domain}} a proposal about',
            'defaultBody' => "Dear {{client_name}},\n\n{{domain}} After looking, a few things caught my eye:\n{{gaps_list}}\n\nIf you like, I can share the details.\n\nThanks,\n{{brand_name}}",
            'pendingTotal' => (int) DB::scalar("select count(*) from outreach_messages where status = 'pending_approval'"),
        ]);
    }

    public function store(Request $request)
    {
        abort_unless($request->user()->can('outreach.manage'), 403);
        $d = $request->validate([
            'name' => ['required', 'string', 'max:120'], 'account_id' => ['required', 'string', 'size:26'],
            'subject_tpl' => ['required', 'string', 'max:200'], 'body_tpl' => ['required', 'string', 'max:20000'],
            'followup_after_days' => ['nullable', 'integer', 'between:1,30'], 'followup_body_tpl' => ['nullable', 'string', 'max:20000'],
            'whatsapp_button' => ['nullable', 'boolean'], 'attach_proposal' => ['nullable', 'boolean'],
        ]);
        if (($d['followup_after_days'] ?? null) !== null && trim((string) ($d['followup_body_tpl'] ?? '')) === '') {
            return back()->withErrors(['followup_body_tpl' => 'If you want a follow-up, give its text as well.'])->withInput();
        }
        $id = CreateWorkspace::newId();
        try {
            DB::insert('insert into outreach_campaigns (id, tenant_id, account_id, name, subject_tpl, body_tpl, followup_after_days, followup_body_tpl, whatsapp_button, attach_proposal, created_by) values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$id, $this->tenant->idOrFail(), $d['account_id'], trim($d['name']), $d['subject_tpl'], $d['body_tpl'], $d['followup_after_days'] ?? null, ($d['followup_after_days'] ?? null) === null ? null : $d['followup_body_tpl'], $request->boolean('whatsapp_button'), $request->boolean('attach_proposal'), $request->user()->getAuthIdentifier()]);
        } catch (\Illuminate\Database\QueryException) {
            return back()->withErrors(['account_id' => 'The sender address is not valid.'])->withInput();
        }

        return redirect()->route('outreach.campaign', $id)->with('status', 'The campaign was created (draft). Add leads, then start.');
    }

    public function show(Request $request, string $id)
    {
        abort_unless($request->user()->can('outreach.view'), 403);
        $c = DB::selectOne('select c.*, a.from_email from outreach_campaigns c join email_accounts a on a.id = c.account_id where c.id = ?', [self::id($id)]) ?? abort(404);

        return view('outreach.campaign', [
            'c' => $c, 'statusBn' => self::STATUS_BN,
            'messages' => DB::select('select m.id, m.to_email, m.step, m.subject, m.status, m.error_code, m.sent_at, m.lead_id from outreach_messages m where m.campaign_id = ? order by m.created_at desc limit 200', [$id]),
            'canManage' => $request->user()->can('outreach.manage'),
        ]);
    }

    public function setStatus(Request $request, string $id)
    {
        abort_unless($request->user()->can('outreach.manage'), 403);
        $d = $request->validate(['status' => ['required', 'in:active,paused,archived']]);
        DB::update('update outreach_campaigns set status = ?, updated_at = now() where id = ?', [$d['status'], self::id($id)]);

        return back()->with('status', 'The campaign’s status changed.');
    }

    /** Drafts one mail per matching lead. Nothing is sent: every draft waits in the approval queue. */
    public function addLeads(Request $request, string $id)
    {
        abort_unless($request->user()->can('outreach.manage'), 403);
        $d = $request->validate(['stage' => ['nullable', 'in:new,contacted,qualified,proposal'], 'min_score' => ['nullable', 'integer', 'between:0,100'], 'limit' => ['required', 'integer', 'between:1,200']]);
        $rows = DB::select('select l.id from leads l where l.deleted_at is null and (?::text is null or l.stage = ?) and l.score >= ? order by l.score desc limit ?',
            [$d['stage'] ?? null, $d['stage'] ?? null, (int) ($d['min_score'] ?? 0), $d['limit']]);
        try {
            $r = $this->svc->queue($this->tenant->idOrFail(), self::id($id), array_map(fn ($x) => trim($x->id), $rows));
        } catch (\DomainException) {
            abort(404);
        }

        return back()->with('status', "{$r['queued']} drafts went to the approval queue. No email: {$r['no_email']}, Blocklisted: {$r['suppressed']}, Already exist: {$r['duplicate']}".($r['no_proposal'] > 0 ? ", No proposal (skipped): {$r['no_proposal']}" : '').'.');
    }

    public function queue(Request $request)
    {
        abort_unless($request->user()->can('outreach.view'), 403);

        return view('outreach.queue', [
            'rows' => DB::select("select m.id, m.to_email, m.step, m.subject, m.body, c.name as campaign, a.from_email from outreach_messages m
                join outreach_campaigns c on c.id = m.campaign_id join email_accounts a on a.id = c.account_id
                where m.status = 'pending_approval' order by m.created_at limit 100"),
            'canApprove' => $request->user()->can('outreach.approve'),
        ]);
    }

    public function decide(Request $request)
    {
        abort_unless($request->user()->can('outreach.approve'), 403);
        $d = $request->validate(['ids' => ['required', 'array', 'min:1', 'max:100'], 'ids.*' => ['string', 'size:26'], 'action' => ['required', 'in:approve,reject']]);
        if ($d['action'] === 'approve') {
            $r = $this->svc->approve($d['ids'], (string) $request->user()->getAuthIdentifier());
            $msg = "{$r['approved']} were approved (they will now be sent within the daily limit)".($r['skipped'] > 0 ? ", {$r['skipped']} were skipped (on the blocklist or already decided)." : '.');
        } else {
            $msg = $this->svc->reject($d['ids']).' were cancelled.';
        }

        return back()->with('status', $msg);
    }

    public function mark(Request $request, string $id)
    {
        abort_unless($request->user()->can('outreach.manage'), 403);
        $d = $request->validate(['as' => ['required', 'in:replied,bounced']]);
        $id = self::id($id);
        $ok = $d['as'] === 'replied' ? $this->svc->markReplied($id) : $this->svc->markBounced($id);

        return back()->with('status', $ok ? 'Marked.' : 'Only sent mail can be marked.');
    }

    public function suppressions(Request $request)
    {
        abort_unless($request->user()->can('outreach.view'), 403);

        return view('outreach.suppressions', [
            'rows' => DB::select('select email, reason, created_at from outreach_suppressions order by created_at desc limit 500'),
            'canManage' => $request->user()->can('outreach.manage'),
        ]);
    }

    public function suppress(Request $request)
    {
        abort_unless($request->user()->can('outreach.manage'), 403);
        $d = $request->validate(['email' => ['required', 'email', 'max:254']]);
        DB::insert("insert into outreach_suppressions (tenant_id, email, reason) values (?, ?, 'manual') on conflict do nothing", [$this->tenant->idOrFail(), strtolower($d['email'])]);

        return back()->with('status', 'Added to the blocklist. No more mail will go to this address.');
    }
}
