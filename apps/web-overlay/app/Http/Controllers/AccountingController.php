<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Accounting\AccText;
use App\Accounting\AccountingRepo;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;

/** Journal, chart of accounts, ledger, trial balance and period lock. Every accounting rule lives in the database functions. */
final class AccountingController extends Controller
{
    public function __construct(private readonly AccountingRepo $acct, private readonly TenantContext $tenant) {}

    private function need(Request $r, string $permission): void
    {
        abort_unless($r->user()->can($permission), 403);
    }

    private static function id(string $id): string
    {
        abort_unless(preg_match('/\A[0-9a-z]{26}\z/', $id) === 1, 404);

        return $id;
    }

    private static function day(?string $v, string $fallback): string
    {
        return $v !== null && preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $v) === 1 && checkdate((int) substr($v, 5, 2), (int) substr($v, 8, 2), (int) substr($v, 0, 4)) ? $v : $fallback;
    }

    private static function today(): string
    {
        return now('Asia/Dhaka')->toDateString();
    }

    public function index(Request $request)
    {
        $this->need($request, 'accounting.view');

        return view('accounting.journal', ['rows' => $this->acct->entries(), 'settings' => $this->acct->settings(), 'canManage' => $request->user()->can('accounting.manage')]);
    }

    public function accounts(Request $request)
    {
        $this->need($request, 'accounting.view');

        return view('accounting.accounts', ['accounts' => $this->acct->accounts(), 'settings' => $this->acct->settings(), 'canSettings' => $request->user()->can('accounting.settings')]);
    }

    public function seed(Request $request)
    {
        $this->need($request, 'accounting.settings');
        $n = $this->acct->seed($this->tenant->idOrFail());

        return back()->with('status', $n > 0 ? "$n accounts were added." : 'The chart already exists.');
    }

    public function storeAccount(Request $request)
    {
        $this->need($request, 'accounting.settings');
        $d = $request->validate(['code' => ['required', 'string', 'max:10'], 'name' => ['required', 'string', 'max:120'], 'name_bn' => ['nullable', 'string', 'max:120'],
            'type' => ['required', 'in:asset,liability,equity,income,expense'], 'parent_id' => ['nullable', 'regex:/\A[0-9a-z]{26}\z/']]);
        $err = AccText::accountError($this->acct->addAccount($this->tenant->idOrFail(), $d['code'], $d['name'], $d['name_bn'] ?? null, $d['type'], ($d['parent_id'] ?? '') === '' ? null : $d['parent_id']));

        return $err === null ? back()->with('status', 'Account added.') : back()->withErrors(['account' => $err])->withInput();
    }

    public function toggleAccount(Request $request, string $id)
    {
        $this->need($request, 'accounting.settings');
        $d = $request->validate(['active' => ['required', 'in:0,1']]);
        $this->acct->setActive(self::id($id), $d['active'] === '1');

        return back()->with('status', 'Saved.');
    }

    public function create(Request $request)
    {
        $this->need($request, 'accounting.manage');

        return view('accounting.create', ['accounts' => array_values(array_filter($this->acct->accounts(), fn ($a) => $a['postable'] && $a['active'])), 'today' => self::today()]);
    }

    public function store(Request $request)
    {
        $this->need($request, 'accounting.manage');
        $d = $request->validate(['entry_date' => ['required', 'date_format:Y-m-d'], 'memo' => ['required', 'string', 'max:300'], 'rows' => ['required', 'array', 'max:60']]);
        $p = AccText::lines($d['rows']);
        if ($p['error'] !== null) {
            return back()->withErrors(['entry' => $p['error']])->withInput();
        }
        $r = $this->acct->post($this->tenant->idOrFail(), (string) $request->user()->id, $d['entry_date'], $d['memo'], $p['lines']);
        $err = AccText::postError($r['result']);

        return $err === null ? redirect()->route('accounting.show', $r['entry_id'])->with('status', 'Entry #'.$r['number'].' Done.') : back()->withErrors(['entry' => $err])->withInput();
    }

    public function show(Request $request, string $id)
    {
        $this->need($request, 'accounting.view');
        $e = $this->acct->entry(self::id($id)) ?? abort(404);

        return view('accounting.show', $e + ['canManage' => $request->user()->can('accounting.manage'), 'today' => self::today()]);
    }

    public function reverse(Request $request, string $id)
    {
        $this->need($request, 'accounting.manage');
        $d = $request->validate(['entry_date' => ['required', 'date_format:Y-m-d'], 'memo' => ['nullable', 'string', 'max:300']]);
        $e = $this->acct->entry(self::id($id)) ?? abort(404);
        $err = AccText::reverseError($this->acct->reverse($e['entry']['id'], (string) $request->user()->id, $d['entry_date'], $d['memo'] ?? null));

        return $err === null ? back()->with('status', 'A cancellation (reversal) entry was created.') : back()->withErrors(['entry' => $err]);
    }

    public function ledger(Request $request)
    {
        $this->need($request, 'accounting.view');
        $accounts = array_values(array_filter($this->acct->accounts(), fn ($a) => $a['postable']));
        $sel = (string) $request->query('account', '');
        $from = self::day($request->query('from'), now('Asia/Dhaka')->startOfMonth()->toDateString());
        $to = self::day($request->query('to'), self::today());
        $rows = preg_match('/\A[0-9a-z]{26}\z/', $sel) === 1 ? $this->acct->ledger($sel, $from, $to) : [];

        return view('accounting.ledger', compact('accounts', 'sel', 'from', 'to', 'rows'));
    }

    public function trial(Request $request)
    {
        $this->need($request, 'accounting.view');
        $asof = self::day($request->query('asof'), self::today());
        $rows = $this->acct->trialBalance($asof);

        return view('accounting.trial', ['rows' => $rows, 'asof' => $asof, 'debit' => array_sum(array_column($rows, 'debit_minor')), 'credit' => array_sum(array_column($rows, 'credit_minor'))]);
    }

    public function settings(Request $request)
    {
        $this->need($request, 'accounting.view');

        return view('accounting.settings', ['s' => $this->acct->settings(), 'log' => $this->acct->lockLog(), 'canSettings' => $request->user()->can('accounting.settings'), 'today' => self::today()]);
    }

    public function lock(Request $request)
    {
        $this->need($request, 'accounting.settings');
        $d = $request->validate(['through' => ['required', 'date_format:Y-m-d']]);
        $err = AccText::lockError($this->acct->lock($this->tenant->idOrFail(), (string) $request->user()->id, $d['through']));

        return $err === null ? back()->with('status', $d['through'].' Books were closed (locked) until.') : back()->withErrors(['lock' => $err]);
    }

    public function unlock(Request $request)
    {
        $this->need($request, 'accounting.settings');
        $d = $request->validate(['to' => ['nullable', 'date_format:Y-m-d'], 'reason' => ['required', 'string', 'max:200']]);
        $err = AccText::lockError($this->acct->unlock($this->tenant->idOrFail(), (string) $request->user()->id, ($d['to'] ?? '') === '' ? null : $d['to'], $d['reason']));

        return $err === null ? back()->with('status', 'The lock was opened (it stays in the log with the reason).') : back()->withErrors(['lock' => $err]);
    }
}
