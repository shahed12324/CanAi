<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Tenancy\CreateWorkspace;
use App\Analyst\AnalystRepo;
use App\Analyst\AnalystService;
use App\Analyst\Facts;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;

/** Plain-Bengali reading of the books: what changed, where the money went, and a clearly-marked estimate. It never changes any accounting data. */
final class AccountingAnalystController extends Controller
{
    public function __construct(private readonly AnalystRepo $repo, private readonly AnalystService $service, private readonly TenantContext $tenant) {}

    private function need(Request $r, string $permission): void
    {
        abort_unless($r->user()->can($permission), 403);
    }

    private static function today(): string
    {
        return now('Asia/Dhaka')->toDateString();
    }

    private const FAIL = [
        'bad_month' => 'The month is not valid — only completed months can be picked.',
        'no_data' => 'There are no income-expense transactions in this period — not enough data for analysis.',
        'failed' => 'The report could not be saved.',
    ];

    public const NOTE = [
        'no_model' => 'AI Model not found',
        'no_credits' => 'Credits were not enough',
        'credits' => 'There was a problem with credit accounting',
        'provider' => 'AI The provider did not respond',
        'engine' => 'AI Could not be run',
        'rejected_unknown_number' => 'AI-text contained numbers not in the books',
        'rejected_unmarked_estimate' => 'AI The forecast "estimated" did not say',
        'rejected_empty' => 'AI-text was not usable',
    ];

    public function index(Request $request)
    {
        $this->need($request, 'accounting.view');
        $last = AnalystService::lastFullMonth(self::today());

        return view('accounting.analyst.index', ['months' => array_reverse(Facts::months($last, 24)), 'recent' => $this->repo->recent(20), 'canRun' => $request->user()->can('accounting.manage')]);
    }

    public function run(Request $request)
    {
        $this->need($request, 'accounting.manage');
        $d = $request->validate(['month' => ['required', 'regex:/\A20\d\d-(0[1-9]|1[0-2])\z/'], 'ai' => ['nullable', 'boolean']]);
        $rid = CreateWorkspace::newId();
        $r = $this->service->analyze($this->tenant->idOrFail(), (string) $request->user()->id, $d['month'], $request->boolean('ai'), self::today(), $rid);

        return $r['status'] === 'ok'
            ? redirect()->route('accounting.analyst.show', $rid)
            : back()->withErrors(['entry' => self::FAIL[$r['status']] ?? 'Could not be done.'])->withInput();
    }

    public function show(Request $request, string $id)
    {
        $this->need($request, 'accounting.view');
        abort_unless(preg_match('/\A[0-9a-z]{26}\z/', $id) === 1, 404);
        $r = $this->repo->report($id) ?? abort(404);

        return view('accounting.analyst.show', ['r' => $r, 'note' => self::NOTE[(string) $r['note']] ?? null]);
    }
}
