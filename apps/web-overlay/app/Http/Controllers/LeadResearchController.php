<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Jobs\ProcessLeadItem;
use App\Leads\LeadStore;
use App\Leads\ResearchLimits;
use App\Leads\UrlList;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Start a website research job from pasted URLs or a CSV, watch its progress, cancel it. */
final class LeadResearchController extends Controller
{
    public function index(Request $request)
    {
        abort_unless($request->user()->can('leads.view'), 403);

        return view('leads.research.index', [
            'jobs' => DB::select("select j.id, j.name, j.status, j.total, j.created_at,
                    count(i.id) filter (where i.status in ('done', 'failed', 'skipped')) as processed,
                    count(i.id) filter (where i.status = 'failed') as failed
                from lead_research_jobs j left join lead_research_items i on i.job_id = j.id
                group by j.id order by j.created_at desc limit 20"),
            'canManage' => $request->user()->can('leads.manage'),
            'maxUrls' => ResearchLimits::fromConfig()->maxUrls(),
        ]);
    }

    public function store(Request $request, TenantContext $tenant, LeadStore $store)
    {
        abort_unless($request->user()->can('leads.manage'), 403);
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:120'],
            'urls' => ['nullable', 'string', 'max:60000'],
            'csv' => ['nullable', 'file', 'max:'.config('leads.csv_max_kb'), 'extensions:csv,txt'],
            'max_pages' => ['nullable', 'integer', 'between:1,8'],
            'render_js' => ['nullable', 'boolean'],
        ]);
        $limits = ResearchLimits::fromConfig();
        if (($reason = $limits->deny()) !== null) {
            return back()->withErrors(['urls' => $reason])->withInput();
        }

        $file = $request->file('csv');
        if ($file !== null) {
            $raw = (string) file_get_contents($file->getRealPath());
            if (! mb_check_encoding($raw, 'UTF-8')) {
                return back()->withErrors(['csv' => 'CSV The file must be UTF-8.'])->withInput();
            }
            $list = UrlList::fromCsv($raw, $limits->maxUrls());
            $type = 'csv';
        } else {
            $list = UrlList::fromText((string) ($data['urls'] ?? ''), $limits->maxUrls());
            $type = 'url';
        }
        if ($list['items'] === []) {
            return back()->withErrors(['urls' => 'No valid website address was found.'])->withInput();
        }

        $tenantId = $tenant->idOrFail();
        $name = trim((string) ($data['name'] ?? '')) ?: ('Research '.now()->format('d M H:i'));
        $jobId = $store->createJob($tenantId, (string) $request->user()->getAuthIdentifier(), $name, $type, $list['items'],
            ['max_pages' => (int) ($data['max_pages'] ?? 4), 'render_js' => (bool) ($data['render_js'] ?? false)]);
        foreach ($store->itemIds($jobId) as $n => $itemId) {
            ProcessLeadItem::dispatch($tenantId, $itemId)->delay(now()->addSeconds($n));    // gentle ramp-up
        }

        $note = 'Research started ('.count($list['items']).' sites).';
        if ($list['duplicates'] > 0) {
            $note .= " {$list['duplicates']} duplicates skipped.";
        }
        if ($list['rejected'] !== []) {
            $note .= ' '.count($list['rejected']).' addresses skipped as not valid.';
        }
        if ($list['truncated']) {
            $note .= " First {$limits->maxUrls()} were taken.";
        }

        return redirect()->route('leads.research.show', $jobId)->with('status', $note);
    }

    public function show(Request $request, string $id)
    {
        abort_unless($request->user()->can('leads.view'), 403);
        abort_unless(preg_match('/\A[0-9a-z]{26}\z/', $id) === 1, 404);
        $job = DB::selectOne('select id, name, status, total, input_type, options, created_at, started_at, finished_at from lead_research_jobs where id = ?', [$id]) ?? abort(404);

        return view('leads.research.show', [
            'job' => $job,
            'items' => DB::select('select i.url, i.domain, i.status, i.result_code, i.company_id, l.id as lead_id, l.score
                from lead_research_items i left join leads l on l.company_id = i.company_id and l.deleted_at is null
                where i.job_id = ? order by i.id limit 500', [$id]),
            'canManage' => $request->user()->can('leads.manage'),
        ]);
    }

    public function cancel(Request $request, string $id)
    {
        abort_unless($request->user()->can('leads.manage'), 403);
        abort_unless(preg_match('/\A[0-9a-z]{26}\z/', $id) === 1, 404);
        DB::transaction(function () use ($id): void {
            DB::update("update lead_research_jobs set status = 'cancelled', finished_at = now() where id = ? and status in ('queued', 'running')", [$id]);
            DB::update("update lead_research_items set status = 'skipped', result_code = 'cancelled', finished_at = now() where job_id = ? and status = 'queued'", [$id]);
        });

        return back()->with('status', 'The research was cancelled. Those already running will finish.');
    }
}
