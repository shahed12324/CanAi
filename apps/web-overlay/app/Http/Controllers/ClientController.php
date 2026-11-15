<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Clients\ClientForm;
use App\Clients\ClientRepo;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/** Agency mode: the workspace's clients, each with its own brand kit and its own Meta / Google / Facebook-Page accounts.  Only labels accounts — nothing is published or spent here. */
final class ClientController extends Controller
{
    public function __construct(private readonly ClientRepo $repo, private readonly TenantContext $tenant) {}

    public function index(Request $request)
    {
        abort_unless($request->user()->can('proposals.view'), 403);

        return view('clients.index', ['clients' => $this->repo->clients(), 'accounts' => $this->repo->accounts(), 'canManage' => $request->user()->can('proposals.manage')]);
    }

    public function save(Request $request)
    {
        abort_unless($request->user()->can('proposals.manage'), 403);
        $d = $request->validate([
            'id' => ['nullable', 'string', 'size:26'], 'name' => ['required', 'string', 'max:120'], 'primary_color' => ['required', 'regex:/\A#[0-9a-fA-F]{6}\z/'], 'accent_color' => ['required', 'regex:/\A#[0-9a-fA-F]{6}\z/'],
            'email' => ['nullable', 'email', 'max:120'], 'phone' => ['nullable', 'string', 'max:40'], 'website' => ['nullable', 'url', 'max:200'], 'address' => ['nullable', 'string', 'max:240'], 'notes' => ['nullable', 'string', 'max:500'],
            'logo' => ['nullable', 'file', 'max:600', 'mimetypes:image/png,image/jpeg'], 'remove_logo' => ['nullable', 'boolean'],
        ]);
        $logo = null;
        $setLogo = false;
        if ($request->hasFile('logo')) {
            $logo = ClientForm::logo((string) file_get_contents($request->file('logo')->getRealPath()));
            if ($logo === null) {
                return back()->withErrors(['logo' => 'The logo must be a PNG or JPEG image (up to 600 KB).'])->withInput();
            }
            $setLogo = true;
        } elseif ($request->boolean('remove_logo')) {
            $setLogo = true;
        }
        $id = ($d['id'] ?? '') !== '' ? $d['id'] : null;
        try {
            $r = $this->repo->save($this->tenant->idOrFail(), (string) $request->user()->getAuthIdentifier(), $id, ['name' => trim($d['name']), 'primary' => (string) ClientForm::colour($d['primary_color']), 'accent' => (string) ClientForm::colour($d['accent_color']),
                'email' => $d['email'] ?? '', 'phone' => $d['phone'] ?? '', 'website' => $d['website'] ?? '', 'address' => $d['address'] ?? '', 'notes' => $d['notes'] ?? '', 'logo' => $logo, 'set_logo' => $setLogo]);
        } catch (\Throwable $e) {
            Log::warning('client save failed', ['error' => $e::class]);
            $r = 'error';
        }

        return $r === 'ok' ? redirect()->route('clients.index')->with('status', ClientForm::message('save', $r)) : back()->withInput()->withErrors(['client' => ClientForm::message('save', $r)]);
    }

    public function archive(Request $request, string $id)
    {
        abort_unless($request->user()->can('proposals.manage'), 403);
        try {
            $r = $this->repo->archive($this->tenant->idOrFail(), (string) $request->user()->getAuthIdentifier(), $id);
        } catch (\Throwable $e) {
            Log::warning('client archive failed', ['error' => $e::class]);
            $r = 'error';
        }

        return $r === 'ok' ? redirect()->route('clients.index')->with('status', ClientForm::message('archive', $r)) : back()->withErrors(['client' => ClientForm::message('archive', $r)]);
    }

    public function assign(Request $request)
    {
        abort_unless($request->user()->can('proposals.manage'), 403);
        $d = $request->validate(['kind' => ['required', 'in:meta,google,social'], 'account_id' => ['required', 'string', 'size:26'], 'client_id' => ['nullable', 'string', 'size:26']]);
        try {
            $r = $this->repo->assign($this->tenant->idOrFail(), (string) $request->user()->getAuthIdentifier(), $d['kind'], $d['account_id'], ($d['client_id'] ?? '') !== '' ? $d['client_id'] : null);
        } catch (\Throwable $e) {
            Log::warning('client assign failed', ['error' => $e::class]);
            $r = 'error';
        }

        return $r === 'ok' ? redirect()->route('clients.index')->with('status', ClientForm::message('assign', $r)) : back()->withErrors(['client' => ClientForm::message('assign', $r)]);
    }
}
