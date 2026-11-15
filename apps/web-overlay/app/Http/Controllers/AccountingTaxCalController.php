<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Accounting\AccText;
use App\Tenancy\TenantContext;
use App\Vat\TaxCalendar;
use App\Vat\TaxCalRepo;
use Illuminate\Http\Request;

/** The person's own list of tax deadlines. No deadline is built in: every day and month is a row the person (or the accountant) sets. */
final class AccountingTaxCalController extends Controller
{
    public function __construct(private readonly TaxCalRepo $repo, private readonly TenantContext $tenant) {}

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

    private static function intOrNull(mixed $v): ?int
    {
        return is_string($v) && preg_match('/\A\d{1,2}\z/', $v) === 1 ? (int) $v : null;
    }

    public function index(Request $request)
    {
        $this->need($request, 'accounting.view');
        $today = self::today();
        $obligations = $this->repo->obligations();
        $done = $this->repo->doneMap(date('Y-m-d', strtotime($today.' -90 days')), date('Y-m-d', strtotime($today.' +120 days')));
        $agenda = TaxCalendar::agenda($obligations, $done, $today);

        return view('accounting.taxcal.index', ['agenda' => $agenda, 'counts' => TaxCalendar::counts($agenda), 'obligations' => $obligations, 'today' => $today, 'canManage' => $request->user()->can('accounting.manage')]);
    }

    public function seed(Request $request)
    {
        $this->need($request, 'accounting.manage');
        $r = $this->repo->seed($this->tenant->idOrFail(), (string) $request->user()->id);

        return $r === 'ok' ? back()->with('status', 'The starter list (draft) was added — verify each date with your accountant.') : back()->withErrors(['entry' => AccText::calError($r)]);
    }

    public function save(Request $request)
    {
        $this->need($request, 'accounting.manage');
        $d = $request->validate(['id' => ['nullable', 'regex:/\A[0-9a-z]{26}\z/'], 'label' => ['required', 'string', 'max:80'], 'kind' => ['required', 'in:vat_return,vds_deposit,tds_deposit,income_tax,advance_tax,license,other'],
            'freq' => ['required', 'in:monthly,quarterly,yearly,once'], 'day' => ['nullable', 'string', 'max:2'], 'month' => ['nullable', 'string', 'max:2'], 'once' => ['nullable', 'date_format:Y-m-d'], 'remind' => ['required', 'string', 'max:2']]);
        $remind = self::intOrNull($d['remind']);
        if ($remind === null) {
            return back()->withErrors(['entry' => AccText::calError('bad_input')])->withInput();
        }
        $once = $d['freq'] === 'once' ? ($d['once'] ?? null) : null;
        $r = $this->repo->save($this->tenant->idOrFail(), (string) $request->user()->id, $d['id'] ?? null, $d['label'], $d['kind'], $d['freq'],
            $d['freq'] === 'once' ? null : self::intOrNull($d['day'] ?? null), in_array($d['freq'], ['quarterly', 'yearly'], true) ? self::intOrNull($d['month'] ?? null) : null, $once, $remind);
        $err = AccText::calError($r);

        return $err === null ? back()->with('status', 'Saved.') : back()->withErrors(['entry' => $err])->withInput();
    }

    public function active(Request $request, string $id)
    {
        $this->need($request, 'accounting.manage');
        $this->repo->setActive($this->tenant->idOrFail(), self::id($id), $request->boolean('active'));

        return back()->with('status', 'Updated.');
    }

    public function done(Request $request, string $id)
    {
        $this->need($request, 'accounting.manage');
        $d = $request->validate(['due' => ['required', 'date_format:Y-m-d'], 'on' => ['required', 'date_format:Y-m-d'], 'note' => ['nullable', 'string', 'max:200']]);
        $err = AccText::calError($this->repo->markDone($this->tenant->idOrFail(), (string) $request->user()->id, self::id($id), $d['due'], $d['on'], $d['note'] ?? null));

        return $err === null ? back()->with('status', 'Marked done.') : back()->withErrors(['entry' => $err]);
    }

    public function undo(Request $request, string $id)
    {
        $this->need($request, 'accounting.manage');
        $d = $request->validate(['due' => ['required', 'date_format:Y-m-d']]);
        $err = AccText::calError($this->repo->undo($this->tenant->idOrFail(), self::id($id), $d['due']));

        return $err === null ? back()->with('status', 'Unticked.') : back()->withErrors(['entry' => $err]);
    }
}
