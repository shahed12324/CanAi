<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Accounting\AccText;
use App\Payroll\PayrollRepo;
use App\Payroll\PayrollText;
use App\Payroll\SalaryFile;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;

/** Employees, their salary structure, and the monthly run (draft -> posted to the books -> paid). Every amount is what the person entered. */
final class AccountingPayrollController extends Controller
{
    public function __construct(private readonly PayrollRepo $repo, private readonly TenantContext $tenant) {}

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

    private static function back(string $result, string $ok)
    {
        $err = PayrollText::error($result);

        return $err === null ? back()->with('status', $ok) : back()->withErrors(['entry' => $err])->withInput();
    }

    public function index(Request $request)
    {
        $this->need($request, 'payroll.view');

        return view('accounting.payroll.index', ['runs' => $this->repo->runs(36), 'period' => substr(self::today(), 0, 7), 'canManage' => $request->user()->can('payroll.manage')]);
    }

    public function employees(Request $request)
    {
        $this->need($request, 'payroll.view');

        return view('accounting.payroll.employees', ['employees' => $this->repo->employees(), 'pay' => $this->repo->payDetails(), 'today' => self::today(), 'canManage' => $request->user()->can('payroll.manage')]);
    }

    public function saveEmployee(Request $request)
    {
        $this->need($request, 'payroll.manage');
        $d = $request->validate(['id' => ['nullable', 'regex:/\A[0-9a-z]{26}\z/'], 'code' => ['required', 'string', 'max:20'], 'name' => ['required', 'string', 'max:80'], 'designation' => ['nullable', 'string', 'max:80'], 'join' => ['required', 'date_format:Y-m-d'], 'end' => ['nullable', 'date_format:Y-m-d']]);

        return self::back($this->repo->saveEmployee($this->tenant->idOrFail(), (string) $request->user()->id, $d['id'] ?? null, $d['code'], $d['name'], $d['designation'] ?? null, $d['join'], $d['end'] ?? null), 'Employee saved.');
    }

    public function employeeActive(Request $request, string $id)
    {
        $this->need($request, 'payroll.manage');
        $this->repo->setEmployeeActive($this->tenant->idOrFail(), self::id($id), $request->boolean('active'));

        return back()->with('status', 'Updated.');
    }

    public function structure(Request $request, string $id)
    {
        $this->need($request, 'payroll.view');
        $e = $this->repo->employee(self::id($id)) ?? abort(404);

        return view('accounting.payroll.structure', ['e' => $e, 'components' => $this->repo->components($id), 'today' => self::today(), 'canManage' => $request->user()->can('payroll.manage')]);
    }

    public function saveComponent(Request $request, string $id)
    {
        $this->need($request, 'payroll.manage');
        $d = $request->validate(['kind' => ['required', 'in:earning,deduction'], 'tax' => ['nullable', 'boolean'], 'label' => ['required', 'string', 'max:60'], 'amount' => ['required', 'string', 'max:20'], 'from' => ['required', 'date_format:Y-m-d']]);
        $amount = AccText::toMinor($d['amount']);
        if ($amount === null) {
            return back()->withErrors(['entry' => 'The money amount is not valid.'])->withInput();
        }

        return self::back($this->repo->saveComponent($this->tenant->idOrFail(), (string) $request->user()->id, self::id($id), $d['kind'], $d['kind'] === 'deduction' && $request->boolean('tax'), $d['label'], $amount, $d['from']), 'Structure saved.');
    }

    public function endComponent(Request $request, string $id, string $component)
    {
        $this->need($request, 'payroll.manage');
        $d = $request->validate(['to' => ['required', 'date_format:Y-m-d']]);

        return self::back($this->repo->endComponent($this->tenant->idOrFail(), self::id($component), $d['to']), 'The line was ended.');
    }

    public function createRun(Request $request)
    {
        $this->need($request, 'payroll.manage');
        $d = $request->validate(['period' => ['required', 'regex:/\A20\d\d-(0[1-9]|1[0-2])\z/']]);
        $rid = \App\Actions\Tenancy\CreateWorkspace::newId();
        $r = $this->repo->createRun($this->tenant->idOrFail(), (string) $request->user()->id, $rid, $d['period']);

        return $r === 'ok' ? redirect()->route('accounting.payroll.run', $rid)->with('status', 'Draft run created — check bonuses/deductions and post to the books.') : back()->withErrors(['entry' => PayrollText::error($r)])->withInput();
    }

    public function run(Request $request, string $id)
    {
        $this->need($request, 'payroll.view');
        $d = $this->repo->run(self::id($id)) ?? abort(404);

        return view('accounting.payroll.run', $d + ['cash' => $this->repo->cashAccounts(), 'pay' => $request->user()->can('payroll.manage') ? $this->repo->payDetails() : [], 'today' => self::today(), 'canManage' => $request->user()->can('payroll.manage')]);
    }

    public function adjust(Request $request, string $id)
    {
        $this->need($request, 'payroll.manage');
        $d = $request->validate(['employee_id' => ['required', 'regex:/\A[0-9a-z]{26}\z/'], 'kind' => ['required', 'in:bonus,deduction'], 'label' => ['required', 'string', 'max:60'], 'amount' => ['required', 'string', 'max:20']]);
        $amount = AccText::toMinor($d['amount']);
        if ($amount === null) {
            return back()->withErrors(['entry' => 'The money amount is not valid.'])->withInput();
        }

        return self::back($this->repo->adjustAdd($this->tenant->idOrFail(), self::id($id), $d['employee_id'], $d['kind'], $d['label'], $amount, $d['kind'] === 'deduction' && $request->boolean('tax')), 'Added.');
    }

    public function adjustRemove(Request $request, string $id)
    {
        $this->need($request, 'payroll.manage');
        $d = $request->validate(['employee_id' => ['required', 'regex:/\A[0-9a-z]{26}\z/'], 'item' => ['required', 'regex:/\A[0-9a-z]{26}\z/']]);

        return self::back($this->repo->adjustRemove($this->tenant->idOrFail(), self::id($id), $d['employee_id'], $d['item']), 'Removed.');
    }

    public function post(Request $request, string $id)
    {
        $this->need($request, 'payroll.manage');
        $d = $request->validate(['date' => ['required', 'date_format:Y-m-d']]);

        return self::back($this->repo->post($this->tenant->idOrFail(), (string) $request->user()->id, self::id($id), $d['date']), 'Posted to the books as the month’s salary.');
    }

    public function pay(Request $request, string $id)
    {
        $this->need($request, 'payroll.manage');
        $d = $request->validate(['date' => ['required', 'date_format:Y-m-d'], 'account_id' => ['required', 'regex:/\A[0-9a-z]{26}\z/']]);

        return self::back($this->repo->pay($this->tenant->idOrFail(), (string) $request->user()->id, self::id($id), $d['date'], $d['account_id']), 'Payment recorded.');
    }

    public function void(Request $request, string $id)
    {
        $this->need($request, 'payroll.manage');
        $d = $request->validate(['date' => ['required', 'date_format:Y-m-d']]);
        $r = $this->repo->void($this->tenant->idOrFail(), (string) $request->user()->id, self::id($id), $d['date']);

        return $r === 'ok' ? redirect()->route('accounting.payroll')->with('status', 'The run was cancelled.') : back()->withErrors(['entry' => PayrollText::error($r)]);
    }

    /** The run's issued state only: draft and void runs have no pay slips or salary file. */
    private function issued(string $id): array
    {
        $d = $this->repo->run(self::id($id)) ?? abort(404);
        abort_unless(in_array($d['run']['status'], ['posted', 'paid'], true), 404);

        return $d;
    }

    public function payDetails(Request $request, string $id)
    {
        $this->need($request, 'payroll.manage');
        $e = $this->repo->employee(self::id($id)) ?? abort(404);
        $d = $this->repo->payDetails()[$id] ?? null;

        return view('accounting.payroll.paydetails', ['e' => $e, 'd' => $d, 'masked' => ['account' => SalaryFile::mask($d['account_no'] ?? null), 'wallet' => SalaryFile::mask($d['wallet_no'] ?? null)]]);
    }

    public function savePayDetails(Request $request, string $id)
    {
        $this->need($request, 'payroll.manage');
        $v = $request->validate(['method' => ['required', 'in:bank,bkash,nagad,cash'], 'bank' => ['nullable', 'string', 'max:80'], 'branch' => ['nullable', 'string', 'max:80'], 'routing' => ['nullable', 'string', 'max:20'], 'account' => ['nullable', 'string', 'max:40'], 'wallet' => ['nullable', 'string', 'max:25']]);
        // only the fields that belong to the chosen method are sent, so a leftover value from another tab cannot make a cash/bKash record "bad"
        $bank = $v['method'] === 'bank';
        $wallet = in_array($v['method'], ['bkash', 'nagad'], true);
        $r = $this->repo->savePayDetails($this->tenant->idOrFail(), (string) $request->user()->id, self::id($id), $v['method'], $bank ? ($v['bank'] ?? null) : null, $bank ? ($v['branch'] ?? null) : null, $bank ? ($v['routing'] ?? null) : null, $bank ? ($v['account'] ?? null) : null, $wallet ? ($v['wallet'] ?? null) : null);
        $err = $r === 'ok' ? null : ($r === 'bad_input' ? 'Payment details are not valid — bank: name and account number (9 digits for routing if given); bKash/Nagad: an 11-digit number starting with 013–019.' : PayrollText::error($r));

        return $err === null ? redirect()->route('accounting.payroll.employees')->with('status', 'Payment details saved.') : back()->withErrors(['entry' => $err])->withInput();
    }

    /** Pay-slip(s): one employee (?e=id) or everyone in the run. Print-friendly page; contains NO bank/wallet number. */
    public function slips(Request $request, string $id)
    {
        $this->need($request, 'payroll.view');
        $d = $this->issued($id);
        $e = (string) $request->query('e', '');
        $lines = $e === '' ? $d['lines'] : array_values(array_filter($d['lines'], fn ($l) => $l['employee_id'] === $e));
        abort_if($lines === [], 404);
        $emps = [];
        foreach ($this->repo->employees() as $x) {
            $emps[$x['id']] = $x;
        }

        return view('accounting.payroll.slips', ['run' => $d['run'], 'lines' => $lines, 'emps' => $emps, 'company' => $this->repo->tenantName($this->tenant->idOrFail())]);
    }

    public function file(Request $request, string $id, string $method)
    {
        $this->need($request, 'payroll.manage');
        abort_unless(in_array($method, ['bank', 'bkash', 'nagad'], true), 404);
        $d = $this->issued($id);
        $plan = SalaryFile::plan($d['lines'], $this->repo->payDetails());
        $csv = SalaryFile::csv($method, $plan['rows'][$method], $d['run']['period']) ?? abort(404);

        return response($csv, 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="salary-'.$d['run']['period'].'-'.$method.'.csv"', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'no-store']);
    }
}
