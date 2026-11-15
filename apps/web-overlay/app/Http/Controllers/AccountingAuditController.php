<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Accounting\AccText;
use App\Audit\AuditRepo;
use App\Audit\AuditText;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;

/** The auditor's desk: findings to confirm or dismiss, automatic checks, and a monthly checklist. It reads the books; it never changes them. */
final class AccountingAuditController extends Controller
{
    public function __construct(private readonly AuditRepo $repo, private readonly TenantContext $tenant) {}

    private function need(Request $r, string $permission): void
    {
        abort_unless($r->user()->can($permission), 403);
    }

    private static function id(string $id): string
    {
        abort_unless(preg_match('/\A[0-9a-z]{26}\z/', $id) === 1, 404);

        return $id;
    }

    private static function today(): string
    {
        return now('Asia/Dhaka')->toDateString();
    }

    public function index(Request $request)
    {
        $this->need($request, 'accounting.view');
        $status = $request->query('status');
        $status = in_array($status, ['open', 'confirmed', 'dismissed', 'all'], true) ? $status : 'open';
        $period = AuditText::period($request->query('period'), substr(self::today(), 0, 7));

        return view('accounting.audit.index', [
            'findings' => $this->repo->findings($status === 'all' ? null : $status, 200), 'status' => $status, 'counts' => $this->repo->counts(),
            'health' => $this->repo->health($this->tenant->idOrFail()), 'settings' => $this->repo->settings(), 'period' => $period, 'checklist' => $this->repo->checklist($period),
            'today' => self::today(), 'canAudit' => $request->user()->can('accounting.audit'), 'canSettings' => $request->user()->can('accounting.settings'),
        ]);
    }

    public function scan(Request $request)
    {
        $this->need($request, 'accounting.audit');
        $n = $this->repo->scan($this->tenant->idOrFail(), (string) $request->user()->id);

        return back()->with('status', $n === 0 ? 'Nothing new was found.' : "{$n} new items were found — see below.");
    }

    public function review(Request $request, string $id)
    {
        $this->need($request, 'accounting.audit');
        $d = $request->validate(['status' => ['required', 'in:confirmed,dismissed,open'], 'note' => ['nullable', 'string', 'max:300']]);
        $err = AccText::auditError($this->repo->review($this->tenant->idOrFail(), (string) $request->user()->id, self::id($id), $d['status'], $d['note'] ?? null));

        return $err === null ? back()->with('status', 'Review saved.') : back()->withErrors(['entry' => $err]);
    }

    public function saveSettings(Request $request)
    {
        $this->need($request, 'accounting.settings');
        $v = $request->validate(['dup_days' => ['required', 'integer'], 'outlier_factor' => ['required', 'integer'], 'min_history' => ['required', 'integer'], 'backdate_days' => ['required', 'integer'], 'cash' => ['required', 'string', 'max:20'], 'round_modulus' => ['required', 'string', 'max:20'], 'round_min' => ['required', 'string', 'max:20']]);
        $cash = AccText::toMinor($v['cash']);
        $mod = AccText::toMinor($v['round_modulus']);
        $min = AccText::toMinor($v['round_min']);
        if ($cash === null || $mod === null || $min === null) {
            return back()->withErrors(['entry' => 'The money amount is not valid.'])->withInput();
        }
        $err = AccText::auditError($this->repo->saveSettings($this->tenant->idOrFail(), (string) $request->user()->id, ['dup_days' => (int) $v['dup_days'], 'outlier_factor' => (int) $v['outlier_factor'], 'min_history' => (int) $v['min_history'],
            'backdate_days' => (int) $v['backdate_days'], 'cash_limit_minor' => $cash, 'round_modulus' => $mod, 'round_min_minor' => $min]));

        return $err === null ? back()->with('status', 'Limits saved — effective from the next scan.') : back()->withErrors(['entry' => $err])->withInput();
    }

    public function saveItem(Request $request)
    {
        $this->need($request, 'accounting.settings');
        $d = $request->validate(['id' => ['nullable', 'regex:/\A[0-9a-z]{26}\z/'], 'label' => ['required', 'string', 'max:120'], 'sort' => ['required', 'integer', 'min:0', 'max:1000']]);
        $err = AccText::auditError($this->repo->saveItem($this->tenant->idOrFail(), (string) $request->user()->id, $d['id'] ?? null, $d['label'], (int) $d['sort']));

        return $err === null ? back()->with('status', 'Saved.') : back()->withErrors(['entry' => $err])->withInput();
    }

    public function itemActive(Request $request, string $id)
    {
        $this->need($request, 'accounting.settings');
        $this->repo->setItemActive($this->tenant->idOrFail(), self::id($id), $request->boolean('active'));

        return back()->with('status', 'Updated.');
    }

    public function seedItems(Request $request)
    {
        $this->need($request, 'accounting.settings');
        $r = $this->repo->seedItems($this->tenant->idOrFail(), (string) $request->user()->id);

        return $r === 'ok' ? back()->with('status', 'The starter checklist was added.') : back()->withErrors(['entry' => AccText::auditError($r)]);
    }

    public function tick(Request $request, string $id)
    {
        $this->need($request, 'accounting.audit');
        $d = $request->validate(['period' => ['required', 'regex:/\A20\d\d-(0[1-9]|1[0-2])\z/'], 'on' => ['required', 'date_format:Y-m-d'], 'note' => ['nullable', 'string', 'max:200']]);
        $err = AccText::auditError($this->repo->tick($this->tenant->idOrFail(), (string) $request->user()->id, self::id($id), $d['period'], $d['on'], $d['note'] ?? null));

        return $err === null ? back()->with('status', 'Ticked.') : back()->withErrors(['entry' => $err]);
    }

    public function untick(Request $request, string $id)
    {
        $this->need($request, 'accounting.audit');
        $d = $request->validate(['period' => ['required', 'regex:/\A20\d\d-(0[1-9]|1[0-2])\z/']]);
        $err = AccText::auditError($this->repo->untick($this->tenant->idOrFail(), self::id($id), $d['period']));

        return $err === null ? back()->with('status', 'Unticked.') : back()->withErrors(['entry' => $err]);
    }
}
