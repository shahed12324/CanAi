<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Accounting\AccText;
use App\Tenancy\TenantContext;
use App\Vat\VatBooks;
use App\Vat\VatCalc;
use App\Vat\VatRepo;
use Illuminate\Http\Request;

/** VAT settings and rates, the purchase/sales registers, the return draft and a record of what was filed. The figures come from the books; NBR filing is done by a person on NBR's system. */
final class AccountingVatController extends Controller
{
    public function __construct(private readonly VatRepo $repo, private readonly TenantContext $tenant) {}

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

    /** @return array{0: string, 1: string} the requested period or the current filing period */
    private function range(Request $r, string $filing): array
    {
        $f = (string) $r->query('from', '');
        $t = (string) $r->query('to', '');
        $valid = fn (string $d) => preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $d) === 1 && checkdate((int) substr($d, 5, 2), (int) substr($d, 8, 2), (int) substr($d, 0, 4));
        if ($valid($f) && $valid($t) && $t >= $f && (strtotime($t) - strtotime($f)) <= 366 * 86400) {
            return [$f, $t];
        }

        return VatBooks::period(self::today(), $filing);
    }

    public function index(Request $request)
    {
        $this->need($request, 'accounting.view');

        return view('accounting.vat.index', ['s' => $this->repo->settings(), 'rates' => $this->repo->rates(), 'returns' => $this->repo->returns(40), 'today' => self::today(), 'canManage' => $request->user()->can('accounting.manage')]);
    }

    public function saveSettings(Request $request)
    {
        $this->need($request, 'accounting.manage');
        $d = $request->validate(['registered' => ['required', 'in:0,1'], 'bin' => ['nullable', 'string', 'max:20'], 'period' => ['required', 'in:monthly,quarterly']]);
        $bin = preg_replace('/\D/', '', strtr((string) ($d['bin'] ?? ''), ['০' => '0', '১' => '1', '২' => '2', '৩' => '3', '৪' => '4', '৫' => '5', '৬' => '6', '৭' => '7', '৮' => '8', '৯' => '9']));
        $err = AccText::vatError($this->repo->saveSettings($this->tenant->idOrFail(), (string) $request->user()->id, $d['registered'] === '1', $bin === '' ? null : $bin, $d['period']));

        return $err === null ? back()->with('status', 'Saved.') : back()->withErrors(['entry' => $err])->withInput();
    }

    public function seed(Request $request)
    {
        $this->need($request, 'accounting.manage');
        $r = $this->repo->seedRates($this->tenant->idOrFail(), (string) $request->user()->id);

        return back()->with('status', $r === 'ok' ? 'Starter rates were added — these come from general information; verify with NBR/your accountant.' : 'The rate already exists.');
    }

    public function saveRate(Request $request)
    {
        $this->need($request, 'accounting.manage');
        $d = $request->validate(['code' => ['required', 'string', 'max:20'], 'label' => ['required', 'string', 'max:80'], 'percent' => ['required', 'string', 'max:10'], 'from' => ['required', 'date_format:Y-m-d']]);
        $bp = VatCalc::parsePercent($d['percent']);
        if ($bp === null) {
            return back()->withErrors(['entry' => 'Give a rate between 0 and 100 (max 2 decimals).'])->withInput();
        }
        $err = AccText::vatError($this->repo->saveRate($this->tenant->idOrFail(), (string) $request->user()->id, $d['code'], $d['label'], $bp, $d['from']));

        return $err === null ? back()->with('status', 'Rate saved.') : back()->withErrors(['entry' => $err])->withInput();
    }

    public function books(Request $request)
    {
        $this->need($request, 'accounting.view');
        $s = $this->repo->settings();
        [$from, $to] = $this->range($request, $s['filing_period']);
        $b = VatBooks::build($this->repo->docs($from, $to));

        return view('accounting.vat.books', $b + ['s' => $s, 'from' => $from, 'to' => $to, 'prev' => VatBooks::previous($from, $s['filing_period']), 'draft' => $this->repo->savedReturn($from, $to, 'draft'), 'filed' => $this->repo->savedReturn($from, $to, 'filed'),
            'today' => self::today(), 'canManage' => $request->user()->can('accounting.manage')]);
    }

    public function csv(Request $request, string $which)
    {
        $this->need($request, 'accounting.view');
        abort_unless(in_array($which, ['sales', 'purchases'], true), 404);
        [$from, $to] = $this->range($request, $this->repo->settings()['filing_period']);
        $b = VatBooks::build($this->repo->docs($from, $to));

        return response(VatBooks::csv($b[$which], $which === 'purchases'), 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="vat-'.$which.'-'.$from.'_'.$to.'.csv"', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function flag(Request $request, string $id)
    {
        $this->need($request, 'accounting.manage');
        $d = $request->validate(['creditable' => ['required', 'in:0,1'], 'reason' => ['nullable', 'string', 'max:120']]);
        $err = AccText::vatError($this->repo->flagDoc(self::id($id), (string) $request->user()->id, $d['creditable'] === '1', $d['reason'] ?? null));

        return $err === null ? back()->with('status', 'Saved.') : back()->withErrors(['entry' => $err]);
    }

    public function saveReturn(Request $request)
    {
        $this->need($request, 'accounting.manage');
        $d = $request->validate(['from' => ['required', 'date_format:Y-m-d'], 'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'], 'note' => ['nullable', 'string', 'max:200']]);
        $b = VatBooks::build($this->repo->docs($d['from'], $d['to']));     // recomputed here: the browser never supplies the figures
        $r = $this->repo->saveReturn($this->tenant->idOrFail(), (string) $request->user()->id, $d['from'], $d['to'], $b['figures'], $d['note'] ?? null);
        $err = AccText::vatError($r['result']);

        return $err === null ? back()->with('status', 'The return draft was saved.') : back()->withErrors(['entry' => $err]);
    }

    public function fileReturn(Request $request, string $id)
    {
        $this->need($request, 'accounting.manage');
        $d = $request->validate(['on' => ['required', 'date_format:Y-m-d'], 'ref' => ['nullable', 'string', 'max:60']]);
        $err = AccText::vatError($this->repo->fileReturn(self::id($id), (string) $request->user()->id, $d['on'], $d['ref'] ?? null));

        return $err === null ? back()->with('status', 'Recorded as filed.') : back()->withErrors(['entry' => $err]);
    }
}
