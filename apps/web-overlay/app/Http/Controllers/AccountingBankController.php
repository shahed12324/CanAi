<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Accounting\AccText;
use App\Accounting\AccountingRepo;
use App\Accounting\BankCsv;
use App\Accounting\BankRepo;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/** Bank / bKash / Nagad statements: import, pair with the books, post what the books lack, and see where the account stands. */
final class AccountingBankController extends Controller
{
    private const FIELDS = ['date', 'description', 'reference', 'debit', 'credit', 'amount', 'balance'];

    public function __construct(private readonly BankRepo $bank, private readonly AccountingRepo $acct, private readonly TenantContext $tenant) {}

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

    /** @return array{id: string, code: string, name: string, name_bn: ?string} */
    private function account(string $id): array
    {
        foreach ($this->bank->accounts() as $a) {
            if ($a['id'] === self::id($id)) {
                return $a;
            }
        }
        abort(404);
    }

    public function index(Request $request)
    {
        $this->need($request, 'accounting.view');
        $rows = [];
        foreach ($this->bank->accounts() as $a) {
            $rows[] = $a + ['rec' => $this->bank->reconciliation($a['id'], self::today())];
        }

        return view('accounting.bank.index', ['rows' => $rows]);
    }

    public function show(Request $request, string $account)
    {
        $this->need($request, 'accounting.view');
        $a = $this->account($account);
        $status = in_array($request->query('status'), ['unmatched', 'matched', 'ignored', 'all'], true) ? (string) $request->query('status') : 'unmatched';
        $lines = $this->bank->lines($a['id'], $status);
        $sug = [];
        foreach ($this->bank->suggestions($a['id'], 3) as $s) {
            $sug[$s['line_id']][] = $s;
        }
        $counters = array_values(array_filter($this->acct->accounts(), fn ($x) => $x['postable'] && $x['active'] && $x['id'] !== $a['id']));

        return view('accounting.bank.show', ['a' => $a, 'rec' => $this->bank->reconciliation($a['id'], self::today()), 'lines' => $lines, 'status' => $status, 'sug' => $sug, 'counters' => $counters,
            'imports' => $this->bank->imports($a['id']), 'canManage' => $request->user()->can('accounting.manage')]);
    }

    public function preview(Request $request, string $account)
    {
        $this->need($request, 'accounting.manage');
        $a = $this->account($account);
        $request->validate(['file' => ['nullable', 'file', 'max:2048', 'mimes:csv,txt'], 'token' => ['nullable', 'regex:/\A[0-9a-zA-Z]{40}\z/'], 'invert' => ['nullable', 'in:1'], 'map' => ['nullable', 'array']]);
        $tenantId = $this->tenant->idOrFail();
        if ($request->hasFile('file')) {
            $text = (string) file_get_contents($request->file('file')->getRealPath());
            $token = Str::random(40);
            Cache::put("bankcsv:$tenantId:$token", ['text' => $text, 'name' => $request->file('file')->getClientOriginalName(), 'account' => $a['id']], 1800);
        } else {
            $token = (string) $request->input('token');
            $stash = Cache::get("bankcsv:$tenantId:$token");
            if (! is_array($stash) || ($stash['account'] ?? null) !== $a['id']) {
                return redirect()->route('accounting.bank.show', $a['id'])->withErrors(['entry' => 'The file timed out — upload again.']);
            }
            $text = $stash['text'];
        }
        $parsed = BankCsv::parse($text, self::pick($request), $request->input('invert') === '1');

        return view('accounting.bank.preview', ['a' => $a, 'token' => $token, 'p' => $parsed, 'invert' => $request->input('invert') === '1', 'fields' => self::FIELDS]);
    }

    public function import(Request $request, string $account)
    {
        $this->need($request, 'accounting.manage');
        $a = $this->account($account);
        $request->validate(['token' => ['required', 'regex:/\A[0-9a-zA-Z]{40}\z/'], 'invert' => ['nullable', 'in:1'], 'map' => ['nullable', 'array']]);
        $tenantId = $this->tenant->idOrFail();
        $token = (string) $request->input('token');
        $stash = Cache::get("bankcsv:$tenantId:$token");
        if (! is_array($stash) || ($stash['account'] ?? null) !== $a['id']) {
            return redirect()->route('accounting.bank.show', $a['id'])->withErrors(['entry' => 'The file timed out — upload again.']);
        }
        $parsed = BankCsv::parse($stash['text'], self::pick($request), $request->input('invert') === '1');
        if (! $parsed['ok']) {
            return redirect()->route('accounting.bank.show', $a['id'])->withErrors(['entry' => $parsed['error'] ?? 'The file has errors — check the preview and fix.']);
        }
        $r = $this->bank->import($tenantId, (string) $request->user()->id, $a['id'], (string) $stash['name'], $parsed['rows']);
        if ($r['result'] !== 'ok') {
            return redirect()->route('accounting.bank.show', $a['id'])->withErrors(['entry' => AccText::bankImportError($r['result'], $r['rows_new'])]);
        }
        Cache::forget("bankcsv:$tenantId:$token");
        $auto = $this->bank->autoMatch($a['id'], (string) $request->user()->id, 3);

        return redirect()->route('accounting.bank.show', $a['id'])->with('status', "New {$r['rows_new']} lines were added, already existed {$r['rows_dup']}; matched automatically {$auto}.");
    }

    public function auto(Request $request, string $account)
    {
        $this->need($request, 'accounting.manage');
        $a = $this->account($account);
        $n = $this->bank->autoMatch($a['id'], (string) $request->user()->id, 3);

        return back()->with('status', "{$n} lines were matched.");
    }

    public function match(Request $request, string $id)
    {
        $this->need($request, 'accounting.manage');
        $d = $request->validate(['seq' => ['required', 'integer', 'min:1']]);
        $err = AccText::bankMatchError($this->bank->match(self::id($id), (int) $d['seq'], (string) $request->user()->id));

        return $err === null ? back()->with('status', 'Matched.') : back()->withErrors(['entry' => $err]);
    }

    public function unmatch(Request $request, string $id)
    {
        $this->need($request, 'accounting.manage');
        $err = AccText::bankMatchError($this->bank->unmatch(self::id($id)));

        return $err === null ? back()->with('status', 'The match was reopened.') : back()->withErrors(['entry' => $err]);
    }

    public function ignore(Request $request, string $id)
    {
        $this->need($request, 'accounting.manage');
        $d = $request->validate(['on' => ['required', 'in:0,1']]);
        $err = AccText::bankMatchError($this->bank->ignore(self::id($id), $d['on'] === '1'));

        return $err === null ? back()->with('status', 'Saved.') : back()->withErrors(['entry' => $err]);
    }

    public function entry(Request $request, string $id)
    {
        $this->need($request, 'accounting.manage');
        $d = $request->validate(['counter' => ['required', 'regex:/\A[0-9a-z]{26}\z/'], 'memo' => ['nullable', 'string', 'max:200']]);
        $err = AccText::bankMatchError($this->bank->createEntry(self::id($id), (string) $request->user()->id, $d['counter'], $d['memo'] ?? null));

        return $err === null ? back()->with('status', 'The entry was made and the line matched.') : back()->withErrors(['entry' => $err]);
    }

    /** @return array<string, ?int> */
    private static function pick(Request $r): array
    {
        $out = [];
        foreach ((array) $r->input('map', []) as $k => $v) {
            if (in_array($k, self::FIELDS, true) && is_string($v) && $v !== '') {
                $out[$k] = ctype_digit($v) ? (int) $v : null;
            } elseif (in_array($k, self::FIELDS, true) && $v === '') {
                $out[$k] = null;
            }
        }

        return $out;
    }
}
