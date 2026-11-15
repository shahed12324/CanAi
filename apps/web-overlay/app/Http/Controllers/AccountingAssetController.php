<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Accounting\AccReport;
use App\Accounting\AccText;
use App\Accounting\AssetRepo;
use App\Accounting\InventoryRepo;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;

/** Fixed assets with monthly depreciation, and inventory valuation against the books. */
final class AccountingAssetController extends Controller
{
    public function __construct(private readonly AssetRepo $assets, private readonly InventoryRepo $inventory, private readonly TenantContext $tenant) {}

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

        return view('accounting.assets.index', ['assets' => $this->assets->assets(), 'pending' => $this->assets->pending(self::today()), 'accounts' => $this->assets->assetAccounts(),
            'today' => self::today(), 'canManage' => $request->user()->can('accounting.manage')]);
    }

    public function store(Request $request)
    {
        $this->need($request, 'accounting.manage');
        $d = $request->validate(['name' => ['required', 'string', 'max:120'], 'account_id' => ['required', 'regex:/\A[0-9a-z]{26}\z/'], 'date' => ['required', 'date_format:Y-m-d'],
            'cost' => ['required', 'string', 'max:20'], 'salvage' => ['nullable', 'string', 'max:20'], 'life' => ['required', 'integer', 'min:1', 'max:600']]);
        $cost = AccText::toMinor($d['cost']);
        $salvage = trim((string) ($d['salvage'] ?? '')) === '' ? 0 : AccText::toMinor((string) $d['salvage']);
        if ($cost === null || $salvage === null) {
            return back()->withErrors(['entry' => 'The money amount is not valid.'])->withInput();
        }
        $err = AccText::assetError($this->assets->register($this->tenant->idOrFail(), (string) $request->user()->id, $d['name'], $d['account_id'], $d['date'], $cost, $salvage, (int) $d['life']));

        return $err === null ? back()->with('status', 'The asset has been registered. (The purchase entry must be made separately beforehand in a bill/expense/journal.)') : back()->withErrors(['entry' => $err])->withInput();
    }

    public function show(Request $request, string $id)
    {
        $this->need($request, 'accounting.view');
        $a = $this->assets->asset(self::id($id)) ?? abort(404);

        return view('accounting.assets.show', ['a' => $a, 'schedule' => $this->assets->schedule($a['id']), 'cash' => $this->assets->cashAccounts(), 'today' => self::today(), 'canManage' => $request->user()->can('accounting.manage')]);
    }

    /** Posts every pending month up to the chosen day, oldest first, one journal entry per month; stops at the first refusal and says where. */
    public function run(Request $request)
    {
        $this->need($request, 'accounting.manage');
        $d = $request->validate(['through' => ['required', 'date_format:Y-m-d']]);
        $through = min($d['through'], self::today());
        $done = 0;
        $total = 0;
        foreach ($this->assets->pending($through) as $m) {
            $r = $this->assets->run($this->tenant->idOrFail(), (string) $request->user()->id, $m['period'].'-01');
            if ($r['result'] === 'ok') {
                $done++;
                $total += $r['total_minor'];
            } elseif ($r['result'] !== 'nothing_to_do') {
                return back()->withErrors(['entry' => "{$m['period']}: ".AccText::depreciationError($r['result']).($done > 0 ? " (Earlier {$done} months already given)" : '')]);
            }
        }

        return back()->with('status', $done === 0 ? 'No months are left to give.' : "{$done} months of depreciation were given (total ".AccText::money($total).' ৳).');
    }

    public function dispose(Request $request, string $id)
    {
        $this->need($request, 'accounting.manage');
        $d = $request->validate(['date' => ['required', 'date_format:Y-m-d'], 'proceeds' => ['nullable', 'string', 'max:20'], 'account_id' => ['nullable', 'regex:/\A[0-9a-z]{26}\z/']]);
        $raw = trim((string) ($d['proceeds'] ?? ''));
        $proceeds = $raw === '' || $raw === '0' ? 0 : AccText::toMinor($raw);
        if ($proceeds === null) {
            return back()->withErrors(['entry' => 'The money amount is not valid.'])->withInput();
        }
        $err = AccText::disposeError($this->assets->dispose(self::id($id), (string) $request->user()->id, $d['date'], $proceeds, ($d['account_id'] ?? '') === '' ? null : $d['account_id']));

        return $err === null ? back()->with('status', 'Asset sale/disposal was recorded.') : back()->withErrors(['entry' => $err])->withInput();
    }

    public function inventory(Request $request)
    {
        $this->need($request, 'accounting.view');
        $asof = AccReport::day($request->query('asof'), self::today());
        $rows = $this->inventory->valuation($asof);
        $value = (int) array_sum(array_column($rows, 'value_minor'));
        $book = $this->inventory->bookBalance($asof);

        return view('accounting.assets.inventory', ['asof' => $asof, 'rows' => $rows, 'value' => $value, 'book' => $book, 'missing' => count(array_filter($rows, fn ($r) => $r['unit_cost_minor'] === null)),
            'counters' => $this->inventory->counterAccounts(), 'today' => self::today(), 'canManage' => $request->user()->can('accounting.manage')]);
    }

    public function setCost(Request $request)
    {
        $this->need($request, 'accounting.manage');
        $d = $request->validate(['costs' => ['required', 'array', 'max:300'], 'date' => ['required', 'date_format:Y-m-d']]);
        $n = 0;
        foreach ($d['costs'] as $variant => $raw) {
            if (! is_string($raw) || trim($raw) === '' || preg_match('/\A[0-9a-z]{26}\z/', (string) $variant) !== 1) {
                continue;
            }
            $c = trim($raw) === '0' ? 0 : AccText::toMinor($raw);
            if ($c === null) {
                return back()->withErrors(['entry' => 'One of the cost amounts is not valid: '.$raw])->withInput();
            }
            $err = AccText::costError($this->inventory->setCost((string) $variant, $c, $d['date'], (string) $request->user()->id));
            if ($err !== null) {
                return back()->withErrors(['entry' => $err])->withInput();
            }
            $n++;
        }

        return back()->with('status', $n === 0 ? 'No cost was given.' : "{$n} product costs saved.");
    }

    public function revalue(Request $request)
    {
        $this->need($request, 'accounting.manage');
        $d = $request->validate(['date' => ['required', 'date_format:Y-m-d'], 'counter' => ['required', 'regex:/\A[0-9a-z]{26}\z/']]);
        $r = $this->inventory->revalue($this->tenant->idOrFail(), (string) $request->user()->id, $d['date'], $d['counter']);
        $err = AccText::revalueError($r['result']);

        return $err === null ? back()->with('status', 'Stock in the books '.AccText::money($r['value_minor']).' matched in ৳ (adjustment '.AccText::money($r['diff_minor']).' ৳).') : back()->withErrors(['entry' => $err]);
    }
}
