<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Accounting\AccText;
use App\Accounting\AccountingRepo;
use App\Accounting\DocumentRepo;
use App\BillScan\BillDraft;
use App\BillScan\BillFileStore;
use App\BillScan\BillScanRepo;
use App\BillScan\BillScanService;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;

/** Photo/PDF of a supplier bill -> AI reads it -> a person reviews the draft -> only then it becomes a bill/expense. */
final class AccountingBillScanController extends Controller
{
    public function __construct(
        private readonly BillScanRepo $repo,
        private readonly BillScanService $service,
        private readonly BillFileStore $files,
        private readonly AccountingRepo $acct,
        private readonly DocumentRepo $docs,
        private readonly TenantContext $tenant,
    ) {}

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

        return view('accounting.billscan.index', ['recent' => $this->repo->recent(30), 'canManage' => $request->user()->can('accounting.manage')]);
    }

    public function upload(Request $request)
    {
        $this->need($request, 'accounting.manage');
        $request->validate(['bill' => ['required', 'file', 'max:5120']]);
        $f = $request->file('bill');
        $bytes = (string) file_get_contents($f->getRealPath());
        $accounts = array_values(array_filter(array_map(fn ($a) => ['code' => (string) $a['code'], 'name' => (string) ($a['name_bn'] ?: $a['name'])], BillDraft::lineAccounts($this->acct->accounts())),
            fn ($a) => preg_match('/\A[0-9]{3,10}\z/', $a['code']) === 1));
        $r = $this->service->scan($this->tenant->idOrFail(), (string) $request->user()->id, (string) $f->getClientOriginalName(), $bytes, $accounts);

        return match ($r['status']) {
            'ready' => redirect()->route('accounting.billscan.show', $r['scan_id'])->with('status', 'The bill was read — check the draft below and confirm.'),
            'duplicate' => redirect()->route('accounting.billscan.show', $r['scan_id'])->with('status', 'This file was already given — the earlier scan was opened (no new cost).'),
            'failed' => back()->withErrors(['entry' => AccText::billScanFailure($r['error'])]),
            default => back()->withErrors(['entry' => (string) $r['error']]),
        };
    }

    public function show(Request $request, string $id)
    {
        $this->need($request, 'accounting.view');
        $s = $this->repo->scan(self::id($id)) ?? abort(404);
        $today = self::today();
        $ctx = ['s' => $s, 'today' => $today, 'canManage' => $request->user()->can('accounting.manage'), 'form' => null, 'accounts' => [], 'suppliers' => [], 'cash' => []];
        if ($s['status'] === 'ready' && is_array($s['extracted'])) {
            $accounts = BillDraft::lineAccounts($this->acct->accounts());
            $suppliers = $this->docs->parties('supplier');
            $ctx['accounts'] = $accounts;
            $ctx['suppliers'] = array_values(array_filter($suppliers, fn ($p) => $p['active']));
            $ctx['cash'] = $this->docs->cashBalances($today);
            $ctx['form'] = BillDraft::prefill($s['extracted'], array_map(fn ($a) => ['id' => $a['id'], 'code' => (string) $a['code']], $accounts), $suppliers, $today);
        }

        return view('accounting.billscan.show', $ctx);
    }

    /** The original upload, only for someone who may view accounting in this workspace (RLS hides other tenants' scans). */
    public function file(Request $request, string $id)
    {
        $this->need($request, 'accounting.view');
        $s = $this->repo->scan(self::id($id)) ?? abort(404);
        abort_unless(in_array($s['status'], ['ready', 'used'], true), 404);
        $bytes = $this->files->get($this->tenant->idOrFail(), $s['id'], (string) $s['mime']) ?? abort(404);

        return response($bytes, 200, ['Content-Type' => (string) $s['mime'], 'Content-Disposition' => 'inline', 'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "sandbox; default-src 'none'; img-src 'self' data:", 'Cache-Control' => 'private, no-store']);
    }

    public function post(Request $request, string $id)
    {
        $this->need($request, 'accounting.manage');
        $id = self::id($id);
        $s = $this->repo->scan($id) ?? abort(404);
        $p = BillDraft::parse($request->all(), self::today());
        if ($p['error'] !== null) {
            return back()->withErrors(['entry' => $p['error']])->withInput();
        }
        $tenantId = $this->tenant->idOrFail();
        $userId = (string) $request->user()->id;
        $party = $p['party_id'];
        if ($party === null && $p['kind'] === 'bill') {
            $party = $this->supplierId($tenantId, $p['new_supplier']);
            if ($party === null) {
                return back()->withErrors(['entry' => 'The supplier could not be created.'])->withInput();
            }
        }
        $r = $this->repo->post($s['id'], $userId, $p['kind'], $party, $p['date'], $p['due'], $p['reference'], $p['lines'], $p['vat_minor'], $p['pay_account']);
        $err = AccText::billPostError($r['result']);
        if ($err !== null) {
            return back()->withErrors(['entry' => $err])->withInput();
        }
        try {
            $this->files->delete($tenantId, $s['id'], (string) $s['mime']);
        } catch (\Throwable) {
            // the document exists; a leftover file is harmless
        }

        return redirect()->route('accounting.doc', $r['doc_id'])->with('status', AccText::kindBn($p['kind']).' '.$r['number'].' Created.');
    }

    public function discard(Request $request, string $id)
    {
        $this->need($request, 'accounting.manage');
        $s = $this->repo->scan(self::id($id)) ?? abort(404);
        if ($this->repo->discard($s['id'], (string) $request->user()->id) !== 'ok') {
            return back()->withErrors(['entry' => 'This can no longer be cancelled.']);
        }
        try {
            $this->files->delete($this->tenant->idOrFail(), $s['id'], (string) $s['mime']);
        } catch (\Throwable) {
        }

        return redirect()->route('accounting.billscan')->with('status', 'Cancelled.');
    }

    /** Existing supplier with this exact name (any case), else a new one. */
    private function supplierId(string $tenantId, string $name): ?string
    {
        $find = function () use ($name): ?string {
            foreach ($this->docs->parties('supplier') as $p) {
                if ($p['active'] && mb_strtolower($p['name']) === mb_strtolower($name)) {
                    return $p['id'];
                }
            }

            return null;
        };
        if (($id = $find()) !== null) {
            return $id;
        }
        if ($this->docs->addParty($tenantId, 'supplier', $name, null, null) !== 'ok') {
            return null;
        }

        return $find();
    }
}
