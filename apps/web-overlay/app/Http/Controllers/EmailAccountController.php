<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Tenancy\CreateWorkspace;
use App\Ai\CredentialVault;
use App\Outreach\DnsAuth;
use App\Outreach\HostGuard;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Sending mailboxes (SMTP). The password is encrypted with the vault key and is never shown again. */
final class EmailAccountController extends Controller
{
    public const ERROR_BN = [
        'smtp_auth' => 'Login failed — correct the username/password', 'oauth_revoked' => 'Permission was withdrawn — connect again', 'oauth_scope' => 'Required permission is missing — connect again and grant all permissions',
        'oauth_not_configured' => 'OAuth key is not set on the server', 'inbox_scope' => 'No permission to read the inbox — replies/bounces will not be caught automatically; connect again',
    ];

    public function index(Request $request)
    {
        abort_unless($request->user()->can('outreach.view'), 403);

        return view('outreach.accounts', [
            'accounts' => DB::select('select id, provider, name, from_email, host, port, security, daily_limit, warmup_started_on::text as started, status, last_error from email_accounts order by created_at'),
            'canManage' => $request->user()->can('outreach.manage'),
            'dns' => session('dns'),
            'oauth' => array_map(fn ($p) => $p->configured(), app('outreach.oauth')),
            'errorBn' => self::ERROR_BN,
        ]);
    }

    public function store(Request $request, TenantContext $tenant, CredentialVault $vault)
    {
        abort_unless($request->user()->can('outreach.manage'), 403);
        $d = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'from_email' => ['required', 'email', 'max:254'],
            'from_name' => ['nullable', 'string', 'max:120', 'regex:/\A[^\r\n<>]*\z/u'],
            'host' => ['required', 'string', 'max:253'],
            'port' => ['required', 'integer', 'between:1,65535'],
            'security' => ['required', 'in:tls,starttls'],
            'username' => ['required', 'string', 'max:254'],
            'password' => ['required', 'string', 'max:500'],
            'daily_limit' => ['required', 'integer', 'between:1,500'],
            'imap_host' => ['nullable', 'string', 'max:253', 'regex:/\A[A-Za-z0-9.-]+\z/'],
            'imap_port' => ['nullable', 'integer', 'between:1,65535', 'required_with:imap_host'],
            'imap_security' => ['nullable', 'in:tls,starttls', 'required_with:imap_host'],
        ]);
        $imapHost = trim((string) ($d['imap_host'] ?? '')) === '' ? null : strtolower(trim($d['imap_host']));
        if ($imapHost !== null && ! HostGuard::allowed($imapHost, HostGuard::resolve(...))) {
            return back()->withErrors(['imap_host' => 'Cannot connect to this IMAP host (it must be a public address on the internet).'])->withInput($request->except('password'));
        }
        $host = strtolower(trim($d['host']));
        if (! HostGuard::allowed($host, HostGuard::resolve(...))) {
            return back()->withErrors(['host' => 'Cannot connect to this SMTP host (it must be a public address on the internet).'])->withInput($request->except('password'));
        }
        $enc = $vault->encrypt($d['password']);
        try {
            DB::insert('insert into email_accounts (id, tenant_id, name, from_email, from_name, host, port, security, username, secret_ct, key_version, daily_limit, imap_host, imap_port, imap_security) values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [CreateWorkspace::newId(), $tenant->idOrFail(), trim($d['name']), strtolower($d['from_email']), $d['from_name'] ?? null, $host, $d['port'], $d['security'], $d['username'], $enc['cipher'], $enc['version'], $d['daily_limit'], $imapHost, $imapHost === null ? null : (int) $d['imap_port'], $imapHost === null ? null : $d['imap_security']]);
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            return back()->withErrors(['from_email' => 'This address has already been added.'])->withInput($request->except('password'));
        }

        return back()->with('status', 'The sender address was added. At most 5 mails go on the first day, rising by 3 every day (warm-up).');
    }

    public function toggle(Request $request, string $id)
    {
        abort_unless($request->user()->can('outreach.manage'), 403);
        abort_unless(preg_match('/\A[0-9a-z]{26}\z/', $id) === 1, 404);
        DB::update("update email_accounts set status = case status when 'active' then 'paused' else 'active' end, updated_at = now() where id = ?", [$id]);

        return back()->with('status', 'Updated.');
    }

    public function dns(Request $request, DnsAuth $auth)
    {
        abort_unless($request->user()->can('outreach.view'), 403);
        $d = $request->validate(['domain' => ['required', 'string', 'max:253', 'regex:/\A[a-z0-9]([a-z0-9.-]*[a-z0-9])?\.[a-z]{2,}\z/i'], 'selector' => ['nullable', 'string', 'max:63']]);

        return back()->with('dns', ['domain' => strtolower($d['domain']), 'result' => $auth->check($d['domain'], $d['selector'] ?? null)]);
    }
}
