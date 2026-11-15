<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Crm\CrmMessages;
use App\Proposals\Pricing;
use App\Proposals\ProposalRepo;
use App\Proposals\ProposalService;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/** Proposal list, create-from-template, editor, status and PDF/DOCX download. */
final class ProposalController extends Controller
{
    public const STATUS_BN = ['draft' => 'draft', 'sent' => 'Sent', 'accepted' => 'Accepted', 'declined' => 'Rejected'];

    public function __construct(private readonly ProposalService $svc, private readonly TenantContext $tenant) {}

    private static function id(string $id): string
    {
        abort_unless(preg_match('/\A[0-9a-z]{26}\z/', $id) === 1, 404);

        return $id;
    }

    public function index(Request $request)
    {
        abort_unless($request->user()->can('proposals.view'), 403);

        return view('proposals.index', [
            'rows' => DB::select('select p.id, p.title, p.status, p.language, p.updated_at, c.name as lead_name from proposals p
                left join leads l on l.id = p.lead_id left join companies c on c.id = l.company_id where p.deleted_at is null order by p.updated_at desc limit 100'),
            'statusBn' => self::STATUS_BN, 'canManage' => $request->user()->can('proposals.manage'),
        ]);
    }

    public function create(Request $request)
    {
        abort_unless($request->user()->can('proposals.manage'), 403);
        $lead = null;
        if ($request->query('lead') !== null) {
            $lid = self::id((string) $request->query('lead'));
            $lead = DB::selectOne('select l.id, c.name, c.domain from leads l join companies c on c.id = l.company_id where l.id = ? and l.deleted_at is null', [$lid]) ?? abort(404);
        }

        return view('proposals.create', [
            'templates' => DB::select('select id, kind, language, name, (tenant_id is null) as shipped from proposal_templates where deleted_at is null order by language desc, kind, name'),
            'lead' => $lead,
        ]);
    }

    public function store(Request $request)
    {
        abort_unless($request->user()->can('proposals.manage'), 403);
        $d = $request->validate(['template_id' => ['required', 'string', 'size:26'], 'lead_id' => ['nullable', 'string', 'size:26'], 'title' => ['nullable', 'string', 'max:200']]);
        $r = $this->svc->createFromTemplate($this->tenant->idOrFail(), (string) $request->user()->getAuthIdentifier(), $d['template_id'], $d['lead_id'] ?? null, $d['title'] ?? null);
        abort_unless($r['ok'], 404);

        return redirect()->route('proposals.edit', $r['id'])->with('status', 'The proposal was created. Now fix the price and text.');
    }

    public function edit(Request $request, ProposalRepo $repo, string $id)
    {
        abort_unless($request->user()->can('proposals.view'), 403);
        $p = $repo->find(self::id($id)) ?? abort(404);
        $d = $p['data'];
        $pr = (array) ($d['pricing'] ?? []);
        $items = array_values(array_filter((array) ($pr['items'] ?? []), 'is_array'));
        $calc = Pricing::compute($items, (int) ($pr['discount_bp'] ?? 0), (int) ($pr['vat_bp'] ?? 0));
        $pct = fn (int $bp) => $bp === 0 ? '' : Pricing::formatPercent($bp, 'en');
        $sections = array_values((array) ($d['sections'] ?? []));
        $sections[] = ['heading' => '', 'body' => ''];                                       // one blank section to add something new

        return view('proposals.edit', [
            'p' => $p, 'sections' => $sections, 'canManage' => $request->user()->can('proposals.manage'),
            'pricingText' => Pricing::toLines($items), 'discount' => $pct((int) ($pr['discount_bp'] ?? 0)), 'vat' => $pct((int) ($pr['vat_bp'] ?? 0)),
            'note' => (string) ($pr['note'] ?? ''), 'calc' => $calc,
            'portfolio' => DB::select('select id, title, url from portfolio_items where deleted_at is null order by created_at desc limit 100'),
            'picked' => (array) ($d['portfolio_ids'] ?? []),
            'lead' => $p['lead_id'] === null ? null : DB::selectOne('select l.id, c.name from leads l join companies c on c.id = l.company_id where l.id = ?', [$p['lead_id']]),
            'statusBn' => self::STATUS_BN,
        ]);
    }

    public function update(Request $request, string $id)
    {
        abort_unless($request->user()->can('proposals.manage'), 403);
        $in = $request->validate([
            'title' => ['nullable', 'string', 'max:500'], 'language' => ['nullable', 'string', 'max:5'], 'client_name' => ['nullable', 'string', 'max:500'],
            'sections' => ['nullable', 'array', 'max:60'], 'sections.*.heading' => ['nullable', 'string', 'max:400'], 'sections.*.body' => ['nullable', 'string', 'max:20000'],
            'pricing_text' => ['nullable', 'string', 'max:20000'], 'discount' => ['nullable', 'string', 'max:12'], 'vat' => ['nullable', 'string', 'max:12'], 'note' => ['nullable', 'string', 'max:2000'],
            'portfolio_ids' => ['nullable', 'array', 'max:50'], 'portfolio_ids.*' => ['string', 'max:40'],
        ]);
        $r = $this->svc->save(self::id($id), $in);
        if (($r['errors']['_'] ?? '') === 'not_found') {
            abort(404);
        }

        return $r['ok']
            ? redirect()->route('proposals.edit', $id)->with('status', 'Saved.')
            : redirect()->route('proposals.edit', $id)->withErrors($r['errors'])->withInput();
    }

    public function status(Request $request, string $id)
    {
        abort_unless($request->user()->can('proposals.manage'), 403);
        $d = $request->validate(['status' => ['required', 'string', 'max:20']]);
        $r = $this->svc->setStatus(self::id($id), $d['status'], (string) $request->user()->getAuthIdentifier());
        abort_if(($r['error'] ?? '') === 'not_found', 404);

        return $r['ok'] ? back()->with('status', 'The status was changed.') : back()->withErrors(['status' => 'The status is not valid.']);
    }

    public function download(Request $request, string $id, string $format)
    {
        abort_unless($request->user()->can('proposals.view'), 403);
        abort_unless(in_array($format, ['pdf', 'docx'], true), 404);
        $tenantId = $this->tenant->idOrFail();
        try {
            $r = $this->svc->render($tenantId, (string) $request->user()->getAuthIdentifier(), self::id($id), $format, CrmMessages::zone($tenantId));
        } catch (\Throwable $e) {
            Log::warning('proposal render failed', ['proposal' => $id, 'format' => $format, 'error' => $e::class]);

            return back()->withErrors(['download' => 'The file could not be made. Try again shortly.']);
        }
        if (! $r['ok']) {
            abort_if($r['error'] === 'not_found', 404);

            return back()->withErrors(['download' => 'PDF Cannot be made now (no Chromium in the AI engine). Take DOCX for now, or ask the admin to rebuild the engine.']);
        }

        return response($r['bytes'], 200, ['Content-Type' => $r['mime'], 'Content-Disposition' => 'attachment; filename="'.$r['filename'].'"', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }
}
