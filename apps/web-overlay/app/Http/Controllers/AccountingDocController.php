<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Accounting\AccText;
use App\Accounting\AccountingRepo;
use App\Accounting\DocumentRepo;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;

/** Customers / suppliers, invoices, bills, expenses, payments, ageing and the cash book. Rules live in the database functions. */
final class AccountingDocController extends Controller
{
    private const KINDS = ['invoices' => 'invoice', 'bills' => 'bill', 'expenses' => 'expense'];

    public function __construct(private readonly DocumentRepo $docs, private readonly AccountingRepo $acct, private readonly TenantContext $tenant) {}

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

    private static function kind(string $plural): string
    {
        return self::KINDS[$plural] ?? abort(404);
    }

    public function parties(Request $request)
    {
        $this->need($request, 'accounting.view');

        return view('accounting.parties', ['parties' => $this->docs->parties(), 'canManage' => $request->user()->can('accounting.manage')]);
    }

    public function addParty(Request $request)
    {
        $this->need($request, 'accounting.manage');
        $d = $request->validate(['kind' => ['required', 'in:customer,supplier'], 'name' => ['required', 'string', 'max:120'], 'phone' => ['nullable', 'string', 'max:30'], 'note' => ['nullable', 'string', 'max:200']]);
        $r = $this->docs->addParty($this->tenant->idOrFail(), $d['kind'], $d['name'], $d['phone'] ?? null, $d['note'] ?? null);

        return $r === 'ok' ? back()->with('status', 'Added.') : back()->withErrors(['entry' => 'Give a name.'])->withInput();
    }

    public function toggleParty(Request $request, string $id)
    {
        $this->need($request, 'accounting.manage');
        $d = $request->validate(['active' => ['required', 'in:0,1']]);
        $this->docs->setPartyActive(self::id($id), $d['active'] === '1');

        return back()->with('status', 'Saved.');
    }

    public function index(Request $request, string $plural)
    {
        $this->need($request, 'accounting.view');
        $kind = self::kind($plural);

        return view('accounting.docs', ['kind' => $kind, 'plural' => $plural, 'rows' => $this->docs->docs($kind), 'canManage' => $request->user()->can('accounting.manage')]);
    }

    public function create(Request $request, string $plural)
    {
        $this->need($request, 'accounting.manage');
        $kind = self::kind($plural);
        $accounts = array_values(array_filter($this->acct->accounts(), fn ($a) => $a['postable'] && $a['active']));
        $lineTypes = $kind === 'invoice' ? ['income'] : ['expense', 'asset'];
        $lineAccounts = array_values(array_filter($accounts, fn ($a) => in_array($a['type'], $lineTypes, true) && ! in_array($a['system_key'], ['receivable', 'payable', 'cash', 'bank', 'mobile_wallet'], true)));
        $cash = array_values(array_filter($this->cashAccounts(), fn ($a) => true));

        return view('accounting.doc_create', ['kind' => $kind, 'plural' => $plural, 'today' => self::today(), 'lineAccounts' => $lineAccounts, 'cash' => $cash,
            'parties' => $kind === 'invoice' ? $this->docs->parties('customer') : $this->docs->parties('supplier')]);
    }

    /** @return list<array<string, mixed>> */
    private function cashAccounts(): array
    {
        return $this->docs->cashBalances(self::today());
    }

    public function store(Request $request, string $plural)
    {
        $this->need($request, 'accounting.manage');
        $kind = self::kind($plural);
        $d = $request->validate(['issue_date' => ['required', 'date_format:Y-m-d'], 'due_date' => ['nullable', 'date_format:Y-m-d'], 'party_id' => ['nullable', 'regex:/\A[0-9a-z]{26}\z/'], 'reference' => ['nullable', 'string', 'max:60'],
            'vat' => ['nullable', 'string', 'max:20'], 'pay_account' => ['nullable', 'regex:/\A[0-9a-z]{26}\z/'], 'rows' => ['required', 'array', 'max:50']]);
        $p = AccText::docLines($d['rows']);
        if ($p['error'] !== null) {
            return back()->withErrors(['entry' => $p['error']])->withInput();
        }
        $vat = trim((string) ($d['vat'] ?? '')) === '' ? 0 : AccText::toMinor((string) $d['vat']);
        if ($vat === null) {
            return back()->withErrors(['entry' => 'The VAT amount is not valid.'])->withInput();
        }
        $r = $this->docs->create($this->tenant->idOrFail(), (string) $request->user()->id, $kind, ($d['party_id'] ?? '') === '' ? null : $d['party_id'], $d['issue_date'], ($d['due_date'] ?? '') === '' ? null : $d['due_date'],
            $d['reference'] ?? null, $p['lines'], $vat, $kind === 'expense' ? ($d['pay_account'] ?? null) : null);
        $err = AccText::docError($r['result']);

        return $err === null ? redirect()->route('accounting.doc', $r['doc_id'])->with('status', AccText::kindBn($kind).' '.$r['number'].' Created.') : back()->withErrors(['entry' => $err])->withInput();
    }

    public function show(Request $request, string $id)
    {
        $this->need($request, 'accounting.view');
        $d = $this->docs->doc(self::id($id)) ?? abort(404);

        return view('accounting.doc_show', $d + ['canManage' => $request->user()->can('accounting.manage'), 'today' => self::today(), 'cash' => $this->cashAccounts()]);
    }

    public function pay(Request $request, string $id)
    {
        $this->need($request, 'accounting.manage');
        $d = $request->validate(['pay_date' => ['required', 'date_format:Y-m-d'], 'amount' => ['required', 'string', 'max:20'], 'account_id' => ['required', 'regex:/\A[0-9a-z]{26}\z/'], 'note' => ['nullable', 'string', 'max:200']]);
        $amt = AccText::toMinor($d['amount']);
        if ($amt === null) {
            return back()->withErrors(['entry' => 'The money amount is not valid.'])->withInput();
        }
        $doc = $this->docs->doc(self::id($id)) ?? abort(404);
        $err = AccText::payError($this->docs->pay($doc['doc']['id'], (string) $request->user()->id, $d['pay_date'], $amt, $d['account_id'], $d['note'] ?? null));

        return $err === null ? back()->with('status', 'Payment was recorded.') : back()->withErrors(['entry' => $err])->withInput();
    }

    public function voidPayment(Request $request, string $id)
    {
        $this->need($request, 'accounting.manage');
        $d = $request->validate(['date' => ['required', 'date_format:Y-m-d']]);
        try {
            $err = AccText::voidError($this->docs->voidPayment(self::id($id), (string) $request->user()->id, $d['date']));
        } catch (\Illuminate\Database\QueryException $e) {
            if (! str_contains($e->getMessage(), 'withheld_tax_deposited')) {
                throw $e;
            }
            $err = 'The tax deducted on this payment has already been deposited to the treasury — it cannot be cancelled.';
        }

        return $err === null ? back()->with('status', 'Payment was cancelled.') : back()->withErrors(['entry' => $err]);
    }

    public function voidDoc(Request $request, string $id)
    {
        $this->need($request, 'accounting.manage');
        $d = $request->validate(['date' => ['required', 'date_format:Y-m-d']]);
        $doc = $this->docs->doc(self::id($id)) ?? abort(404);
        $err = AccText::voidError($this->docs->voidDoc($doc['doc']['id'], (string) $request->user()->id, $d['date']));

        return $err === null ? back()->with('status', 'Cancelled.') : back()->withErrors(['entry' => $err]);
    }

    public function aging(Request $request)
    {
        $this->need($request, 'accounting.view');
        $kind = $request->query('kind') === 'bill' ? 'bill' : 'invoice';
        $asof = preg_match('/\A\d{4}-\d{2}-\d{2}\z/', (string) $request->query('asof')) === 1 ? (string) $request->query('asof') : self::today();
        $rows = $this->docs->aging($kind, $asof);
        $buckets = ['current' => 0, '1-30' => 0, '31-60' => 0, '61-90' => 0, '90+' => 0];
        foreach ($rows as $r) {
            $buckets[$r['bucket']] += (int) $r['balance_minor'];
        }

        return view('accounting.aging', compact('kind', 'asof', 'rows', 'buckets'));
    }

    public function cash(Request $request)
    {
        $this->need($request, 'accounting.view');
        $all = array_values(array_filter($this->acct->accounts(), fn ($a) => $a['postable'] && $a['type'] === 'asset' && ! in_array($a['system_key'], ['receivable', 'payable', 'inventory', 'accum_depreciation', 'vat_input'], true)));

        return view('accounting.cash', ['rows' => $this->docs->cashBalances(self::today()), 'candidates' => $all, 'canSettings' => $request->user()->can('accounting.settings')]);
    }

    public function markCash(Request $request)
    {
        $this->need($request, 'accounting.settings');
        $d = $request->validate(['account_id' => ['required', 'regex:/\A[0-9a-z]{26}\z/'], 'on' => ['required', 'in:0,1']]);
        $r = $this->docs->markCash($d['account_id'], $d['on'] === '1');

        return $r === 'ok' ? back()->with('status', 'Saved.') : back()->withErrors(['entry' => 'This account cannot be marked as cash/bank.']);
    }
}
