<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Accounting\AccText;
use App\Accounting\DocumentRepo;
use App\Tenancy\TenantContext;
use App\Vat\TaxItems;
use App\Vat\TaxRepo;
use App\Vat\VatCalc;
use Illuminate\Http\Request;

/** Tax withheld at source (VDS/TDS), advance tax (AIT), and depositing it. Rates are the person's rules; every amount is what the person confirmed. */
final class AccountingTaxController extends Controller
{
    public function __construct(private readonly TaxRepo $repo, private readonly DocumentRepo $docs, private readonly TenantContext $tenant) {}

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

        return view('accounting.tax.index', ['sum' => $this->repo->summary(), 'rules' => $this->repo->rules(), 'items' => $this->repo->withholdings(100), 'deposits' => $this->repo->deposits(30), 'advances' => $this->repo->advances(30),
            'cash' => $this->repo->cashAccounts(), 'today' => self::today(), 'canManage' => $request->user()->can('accounting.manage')]);
    }

    public function saveRule(Request $request)
    {
        $this->need($request, 'accounting.manage');
        $d = $request->validate(['kind' => ['required', 'in:vds,tds'], 'code' => ['required', 'string', 'max:20'], 'label' => ['required', 'string', 'max:80'], 'percent' => ['required', 'string', 'max:10'], 'from' => ['required', 'date_format:Y-m-d']]);
        $bp = VatCalc::parsePercent($d['percent']);
        if ($bp === null) {
            return back()->withErrors(['entry' => 'Give a rate between 0 and 100 (max 2 decimals).'])->withInput();
        }
        $err = AccText::taxError($this->repo->saveRule($this->tenant->idOrFail(), (string) $request->user()->id, $d['kind'], $d['code'], $d['label'], $bp, $d['from']));

        return $err === null ? back()->with('status', 'Rule saved.') : back()->withErrors(['entry' => $err])->withInput();
    }

    /** The payment-with-tax-withheld form for one bill or invoice. */
    public function settleForm(Request $request, string $doc)
    {
        $this->need($request, 'accounting.manage');
        $d = $this->docs->doc(self::id($doc)) ?? abort(404);
        abort_unless(in_array($d['doc']['kind'], ['bill', 'invoice'], true) && $d['doc']['status'] === 'open', 404);

        return view('accounting.tax.settle', ['d' => $d, 'rules' => $this->repo->rules(), 'cash' => $this->repo->cashAccounts(), 'today' => self::today()]);
    }

    public function settle(Request $request, string $doc)
    {
        $this->need($request, 'accounting.manage');
        $d = $this->docs->doc(self::id($doc)) ?? abort(404);
        $v = $request->validate(['date' => ['required', 'date_format:Y-m-d'], 'gross' => ['required', 'string', 'max:20'], 'account_id' => ['required', 'regex:/\A[0-9a-z]{26}\z/'], 'note' => ['nullable', 'string', 'max:200'], 'rows' => ['required', 'array', 'max:12']]);
        $gross = AccText::toMinor($v['gross']);
        if ($gross === null) {
            return back()->withErrors(['entry' => 'The money amount is not valid.'])->withInput();
        }
        $p = TaxItems::parse($v['rows'], $gross, $this->repo->rules(), $v['date']);
        if ($p['error'] !== null) {
            return back()->withErrors(['entry' => $p['error']])->withInput();
        }
        $err = AccText::taxError($this->repo->settle((string) $request->user()->id, $d['doc']['id'], $v['date'], $gross, $v['account_id'], $v['note'] ?? null, $p['items']));

        return $err === null ? redirect()->route('accounting.doc', $d['doc']['id'])->with('status', 'Payment was recorded — cash out '.AccText::money($gross - $p['withheld_minor']).' ৳, tax '.AccText::money($p['withheld_minor']).' ৳.') : back()->withErrors(['entry' => $err])->withInput();
    }

    public function cert(Request $request, string $id)
    {
        $this->need($request, 'accounting.manage');
        $v = $request->validate(['cert' => ['nullable', 'string', 'max:40']]);
        $err = AccText::taxError($this->repo->setCert(self::id($id), (string) $request->user()->id, $v['cert'] ?? null));

        return $err === null ? back()->with('status', 'Saved.') : back()->withErrors(['entry' => $err]);
    }

    public function deposit(Request $request)
    {
        $this->need($request, 'accounting.manage');
        $v = $request->validate(['kind' => ['required', 'in:vds,tds'], 'date' => ['required', 'date_format:Y-m-d'], 'upto' => ['required', 'date_format:Y-m-d'], 'account_id' => ['required', 'regex:/\A[0-9a-z]{26}\z/'], 'challan' => ['nullable', 'string', 'max:60']]);
        $r = $this->repo->deposit($this->tenant->idOrFail(), (string) $request->user()->id, $v['kind'], $v['date'], $v['upto'], $v['account_id'], $v['challan'] ?? null);
        $err = AccText::taxError($r['result']);

        return $err === null ? back()->with('status', 'Deposit to the treasury was recorded — '.AccText::money($r['amount_minor']).' ৳.') : back()->withErrors(['entry' => $err])->withInput();
    }

    public function advancePay(Request $request)
    {
        $this->need($request, 'accounting.manage');
        $v = $request->validate(['date' => ['required', 'date_format:Y-m-d'], 'amount' => ['required', 'string', 'max:20'], 'account_id' => ['required', 'regex:/\A[0-9a-z]{26}\z/'], 'ref' => ['nullable', 'string', 'max:60'], 'note' => ['nullable', 'string', 'max:200']]);
        $amt = AccText::toMinor($v['amount']);
        if ($amt === null) {
            return back()->withErrors(['entry' => 'The money amount is not valid.'])->withInput();
        }
        $err = AccText::taxError($this->repo->advancePay($this->tenant->idOrFail(), (string) $request->user()->id, $v['date'], $amt, $v['account_id'], $v['ref'] ?? null, $v['note'] ?? null));

        return $err === null ? back()->with('status', 'Advance tax (AIT) was recorded.') : back()->withErrors(['entry' => $err])->withInput();
    }

    public function advanceVoid(Request $request, string $id)
    {
        $this->need($request, 'accounting.manage');
        $err = AccText::taxError($this->repo->advanceVoid(self::id($id), (string) $request->user()->id, self::today()));

        return $err === null ? back()->with('status', 'Cancelled (a reverse entry was made).') : back()->withErrors(['entry' => $err]);
    }
}
