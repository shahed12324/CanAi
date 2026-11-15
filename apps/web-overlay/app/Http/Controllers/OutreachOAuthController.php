<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Tenancy\CreateWorkspace;
use App\Ai\CredentialVault;
use App\Outreach\OAuthFailure;
use App\Outreach\OAuthProvider;
use App\Outreach\Pkce;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** "Connect Gmail" / "Connect Outlook": OAuth 2.0 authorization code flow with PKCE and a one-time state bound to the session. */
final class OutreachOAuthController extends Controller
{
    private const ERRORS = [
        'google_exchange_failed' => 'Google The connection was not completed. Try again.', 'google_no_refresh_token' => 'Google Did not grant permanent permission. Connect again and tick all permissions.',
        'google_scope_missing' => 'Permission to send email was not granted. Connect again and grant all permissions.', 'google_email_unverified' => 'Google The account’s email could not be verified.',
        'microsoft_exchange_failed' => 'Microsoft The connection was not completed. Try again.', 'microsoft_no_refresh_token' => 'Microsoft Did not grant permanent permission. Connect again.',
        'microsoft_scope_missing' => 'Permission to send email was not granted. Connect again and grant all permissions.', 'microsoft_email_unknown' => 'Microsoft The account’s email address was not found.',
    ];

    /** @return array<string, OAuthProvider> */
    private function providers(): array
    {
        return app('outreach.oauth');
    }

    private function redirectUri(string $provider): string
    {
        return rtrim((string) config('app.url'), '/').route('outreach.oauth.callback', ['provider' => $provider], false);
    }

    public function start(Request $request, string $provider)
    {
        abort_unless($request->user()->can('outreach.manage'), 403);
        $p = $this->providers()[$provider] ?? abort(404);
        if (! $p->configured()) {
            return redirect()->route('outreach.accounts')->withErrors(['oauth' => 'The OAuth key required for this connection has not been set on the server yet.']);
        }
        $state = bin2hex(random_bytes(24));
        $verifier = Pkce::verifier();
        $request->session()->put('outreach_oauth', ['provider' => $provider, 'state' => $state, 'verifier' => $verifier, 'tenant' => app(TenantContext::class)->idOrFail(), 'user' => (string) $request->user()->getAuthIdentifier(), 'expires' => time() + 600]);

        return redirect()->away($p->authUrl($this->redirectUri($provider), $state, Pkce::challenge($verifier)));
    }

    public function callback(Request $request, string $provider, TenantContext $tenant, CredentialVault $vault)
    {
        abort_unless($request->user()->can('outreach.manage'), 403);
        $p = $this->providers()[$provider] ?? abort(404);
        $saved = $request->session()->pull('outreach_oauth');                       // one use only, whatever happens next
        $back = redirect()->route('outreach.accounts');
        if (! is_array($saved) || $saved['provider'] !== $provider || $saved['expires'] < time() || $saved['tenant'] !== $tenant->idOrFail() || $saved['user'] !== (string) $request->user()->getAuthIdentifier()
            || ! is_string($request->query('state')) || ! hash_equals($saved['state'], $request->query('state'))) {
            return $back->withErrors(['oauth' => 'The connection request has expired or is invalid. Press the button and start again.']);
        }
        if ($request->query('error') !== null || ! is_string($request->query('code')) || $request->query('code') === '') {
            return $back->withErrors(['oauth' => 'The connection was cancelled or permission was not granted.']);
        }
        try {
            $t = $p->exchange((string) $request->query('code'), $saved['verifier'], $this->redirectUri($provider));
        } catch (OAuthFailure $e) {
            return $back->withErrors(['oauth' => self::ERRORS[$e->getMessage()] ?? 'The connection was not completed.']);
        }
        $enc = $vault->encrypt($t['refresh_token']);
        DB::insert("insert into email_accounts (id, tenant_id, name, from_email, provider, refresh_ct, key_version) values (?, ?, ?, ?, ?, ?, ?)
            on conflict (tenant_id, from_email) do update set provider = excluded.provider, refresh_ct = excluded.refresh_ct, key_version = excluded.key_version,
                secret_ct = null, last_error = null, last_error_at = null, inbox_checked_at = null, status = 'active', updated_at = now()",
            [CreateWorkspace::newId(), $tenant->idOrFail(), $provider === 'gmail' ? 'Gmail' : 'Outlook', $t['email'], $provider, $enc['cipher'], $enc['version']]);

        return $back->with('status', $t['email'].' Connected. At most 5 mails go on the first day, rising by 3 every day.');
    }
}
