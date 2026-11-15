<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Clients\ClientRepo;
use App\Seo\SeoRepo;
use App\Seo\SeoService;
use App\Seo\SeoText;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/** SEO audit: check a website's basic on-page SEO (rule-based, no AI, no credits) and keep the results to compare over time.  Read only — nothing is published or changed on the site. */
final class SeoController extends Controller
{
    public function __construct(private readonly SeoService $svc, private readonly SeoRepo $repo, private readonly ClientRepo $clients, private readonly TenantContext $tenant) {}

    public function index(Request $request)
    {
        abort_unless($request->user()->can('ads.view'), 403);

        return view('seo.index', ['audits' => $this->repo->recent(50), 'clients' => $this->clients->clients(), 'canManage' => $request->user()->can('ads.manage')]);
    }

    public function run(Request $request)
    {
        abort_unless($request->user()->can('ads.manage'), 403);
        $d = $request->validate(['url' => ['required', 'string', 'max:2048'], 'max_pages' => ['nullable', 'integer', 'between:1,8'], 'keyword' => ['nullable', 'string', 'max:80'], 'client_id' => ['nullable', 'string', 'size:26']]);
        $r = $this->svc->run($this->tenant->idOrFail(), (string) $request->user()->getAuthIdentifier(), $d['url'], (int) ($d['max_pages'] ?? 5), $d['keyword'] ?? null, ($d['client_id'] ?? '') !== '' ? $d['client_id'] : null);
        if ($r['outcome'] === 'ok') {
            $note = $r['status'] === 'ok' ? null : SeoText::message((string) $r['status']);

            return redirect()->route('seo.show', $r['id'])->with('status', $note ?? SeoText::message('ok'));
        }
        if ($r['outcome'] === 'error') {
            Log::warning('seo audit save failed');
        }

        return back()->withInput()->withErrors(['seo' => SeoText::message($r['outcome'])]);
    }

    public function show(Request $request, string $id)
    {
        abort_unless($request->user()->can('ads.view'), 403);
        $audit = $this->repo->find($id);
        abort_if($audit === null, 404);

        return view('seo.show', ['a' => $audit, 'canManage' => $request->user()->can('ads.manage')]);
    }

    public function delete(Request $request, string $id)
    {
        abort_unless($request->user()->can('ads.manage'), 403);
        try {
            $r = $this->repo->delete($this->tenant->idOrFail(), (string) $request->user()->getAuthIdentifier(), $id);
        } catch (\Throwable $e) {
            Log::warning('seo audit delete failed', ['error' => $e::class]);
            $r = 'error';
        }

        return $r === 'ok' ? redirect()->route('seo.index')->with('status', 'The audit was deleted.') : back()->withErrors(['seo' => SeoText::message($r === 'missing' ? 'missing' : 'error')]);
    }
}
