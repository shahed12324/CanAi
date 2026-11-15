<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Accounting\AccText;
use App\Accounting\AutopostRepo;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;

/** Automatic posting of delivered shop orders, the "post pending" list, and what the couriers owe us. */
final class AccountingAutopostController extends Controller
{
    public function __construct(private readonly AutopostRepo $repo, private readonly TenantContext $tenant) {}

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

        return view('accounting.autopost.index', ['enabled' => $this->repo->enabled(), 'counts' => $this->repo->counts(), 'queue' => $this->repo->queue(100), 'receivable' => $this->repo->receivable(),
            'settlements' => $this->repo->settlements(30), 'cash' => $this->repo->cashAccounts(), 'today' => self::today(), 'canManage' => $request->user()->can('accounting.manage')]);
    }

    public function toggle(Request $request)
    {
        $this->need($request, 'accounting.manage');
        $d = $request->validate(['on' => ['required', 'in:0,1']]);
        $on = $d['on'] === '1';
        $err = AccText::autopostError($this->repo->setEnabled($this->tenant->idOrFail(), (string) $request->user()->id, $on));

        return $err === null ? back()->with('status', $on ? 'Automatic posting is on — from now on delivered orders will go into the books.' : 'Automatic posting is off (pending ones stay).') : back()->withErrors(['entry' => $err]);
    }

    public function run(Request $request)
    {
        $this->need($request, 'accounting.manage');
        $r = $this->repo->run($this->tenant->idOrFail(), (string) $request->user()->id, 200);
        $msg = "Posted {$r['posted']} items";
        if ($r['skipped'] > 0) {
            $msg .= ", Dismissed {$r['skipped']} items";
        }
        if ($r['failed'] > 0) {
            return back()->with('status', $msg)->withErrors(['entry' => "{$r['failed']} posts did not go through — see the reasons in the list below."]);
        }

        return back()->with('status', $msg === '0 posted' ? 'Nothing to post.' : $msg);
    }

    public function skip(Request $request, string $seq)
    {
        $this->need($request, 'accounting.manage');
        abort_unless(preg_match('/\A[1-9][0-9]{0,17}\z/', $seq) === 1, 404);
        $d = $request->validate(['reason' => ['nullable', 'string', 'max:150']]);
        $res = $this->repo->skip((int) $seq, (string) $request->user()->id, (string) ($d['reason'] ?? ''));

        return $res === 'ok' ? back()->with('status', 'Skipped — this order’s entry must be made by hand.') : back()->withErrors(['entry' => 'This is no longer pending.']);
    }

    public function settle(Request $request)
    {
        $this->need($request, 'accounting.manage');
        $d = $request->validate(['courier' => ['required', 'in:steadfast,pathao,redx'], 'date' => ['required', 'date_format:Y-m-d'], 'gross' => ['required', 'string', 'max:20'], 'fee' => ['nullable', 'string', 'max:20'],
            'account_id' => ['required', 'regex:/\A[0-9a-z]{26}\z/'], 'note' => ['nullable', 'string', 'max:200']]);
        $gross = AccText::toMinor($d['gross']);
        $raw = trim((string) ($d['fee'] ?? ''));
        $fee = $raw === '' || $raw === '0' ? 0 : AccText::toMinor($raw);
        if ($gross === null || $fee === null) {
            return back()->withErrors(['entry' => 'The money amount is not valid.'])->withInput();
        }
        $err = AccText::settleError($this->repo->settle($this->tenant->idOrFail(), (string) $request->user()->id, $d['date'], $d['courier'], $gross, $fee, $d['account_id'], $d['note'] ?? null));

        return $err === null ? back()->with('status', 'Receipt of courier money was recorded.') : back()->withErrors(['entry' => $err])->withInput();
    }

    public function voidSettlement(Request $request, string $id)
    {
        $this->need($request, 'accounting.manage');
        $err = AccText::settleError($this->repo->voidSettlement(self::id($id), (string) $request->user()->id, self::today()));

        return $err === null ? back()->with('status', 'Cancelled (a reverse entry was made).') : back()->withErrors(['entry' => $err]);
    }
}
