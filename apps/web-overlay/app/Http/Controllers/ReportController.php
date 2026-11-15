<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Clients\ClientRepo;
use App\Crm\CrmMessages;
use App\Reports\ReportRepo;
use App\Reports\ReportService;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/** Client reports: weekly / monthly marketing report (Meta, Google Ads, social) as an on-screen preview, PDF or DOCX, branded with the workspace's brand kit.  Read only — nothing is sent anywhere. */
final class ReportController extends Controller
{
    public function __construct(private readonly ReportService $svc, private readonly ReportRepo $repo, private readonly TenantContext $tenant, private readonly ClientRepo $clients) {}

    private static function can(Request $r): void
    {
        abort_unless($r->user()->can('ads.view'), 403);
    }

    public function index(Request $request)
    {
        self::can($request);
        $brand = $this->repo->brand();

        return view('reports.index', ['brand' => $brand['name'] ?? null, 'social' => $request->user()->can('social.view'), 'doc' => null, 'clients' => $this->clients->clients()]);
    }

    public function generate(Request $request)
    {
        self::can($request);
        $d = $request->validate(['kind' => ['required', 'in:week,month'], 'language' => ['required', 'in:bn,en'], 'client_name' => ['nullable', 'string', 'max:200'], 'client_id' => ['nullable', 'string', 'size:26'], 'notes' => ['nullable', 'string', 'max:3000'], 'action' => ['required', 'in:preview,pdf,docx']]);
        $tenantId = $this->tenant->idOrFail();
        $zone = CrmMessages::zone($tenantId);
        $social = $request->user()->can('social.view');
        $clients = $this->clients->clients();
        $picked = null;
        if (($d['client_id'] ?? '') !== '') {
            $picked = array_values(array_filter($clients, fn ($c) => $c['id'] === $d['client_id']))[0] ?? null;
            if ($picked === null) {
                return back()->withInput()->withErrors(['report' => 'The client was not found or is archived.']);
            }
        }
        $opts = ['kind' => $d['kind'], 'language' => $d['language'], 'client_name' => trim((string) ($d['client_name'] ?? '')) !== '' ? (string) $d['client_name'] : ($picked['name'] ?? ''), 'notes' => (string) ($d['notes'] ?? ''), 'client_id' => $picked['id'] ?? null];
        $msg = ['no_data' => 'There is no ad or social data to show in this period — check whether accounts are connected and synced.', 'bad_period' => 'The period is not valid.',
            'unavailable' => 'PDF Cannot be made now (no Chromium in the AI engine). Take DOCX for now, or ask the admin to rebuild the engine.'];
        try {
            if ($d['action'] === 'preview') {
                $b = $this->svc->build($opts, $zone, $social);
                if ($b['error'] !== null) {
                    return back()->withInput()->withErrors(['report' => $msg[$b['error']] ?? 'The report could not be made.']);
                }

                $request->flash();

                return view('reports.index', ['brand' => $this->repo->brand($picked['id'] ?? null)['name'] ?? null, 'social' => $social, 'doc' => $b['doc'], 'old_kind' => $d['kind'], 'clients' => $clients]);
            }
            $r = $this->svc->render($tenantId, (string) $request->user()->getAuthIdentifier(), $opts, $zone, $social, $d['action']);
        } catch (\Throwable $e) {
            Log::warning('report failed', ['action' => $d['action'], 'error' => $e::class]);

            return back()->withInput()->withErrors(['report' => 'The report could not be made. Try again shortly.']);
        }
        if (! $r['ok']) {
            return back()->withInput()->withErrors(['report' => $msg[$r['error']] ?? 'The report could not be made.']);
        }

        return response($r['bytes'], 200, ['Content-Type' => $r['mime'], 'Content-Disposition' => 'attachment; filename="'.$r['filename'].'"', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }
}
