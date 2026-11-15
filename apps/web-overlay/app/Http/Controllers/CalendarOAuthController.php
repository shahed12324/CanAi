<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Calendar\CalendarAccounts;
use App\Outreach\OAuthFailure;
use App\Outreach\OAuthProvider;
use App\Outreach\Pkce;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;

/** "Connect Google Calendar / Outlook Calendar": OAuth 2.0 code flow with PKCE and a one-time state bound to the session.  Personal: it connects the signed-in person's own calendar. */
final class CalendarOAuthController extends Controller
{
    private const ERRORS = [
        'google_exchange_failed' => 'Google The connection was not completed. Try again.', 'google_no_refresh_token' => 'Google Did not grant permanent permission. Connect again and grant all permissions.',
        'google_scope_missing' => 'Calendar permission was not granted. Connect again and grant all permissions.', 'google_email_unverified' => 'Google The email could not be verified.',
        'microsoft_exchange_failed' => 'Microsoft The connection was not completed. Try again.', 'microsoft_no_refresh_token' => 'Microsoft Did not grant permanent permission. Connect again and grant all permissions.',
        'microsoft_scope_missing' => 'Calendar permission was not granted. Connect again and grant all permissions.', 'microsoft_email_unknown' => 'Microsoft The email is unknown.',
    ];

    /** @return array<string, OAuthProvider> */
    private function providers(): array
    {
        return app('calendar.oauth');
    }

    private function redirectUri(string $provider): string
    {
        return rtrim((string) config('app.url'), '/').route('connect.oauth.callback', ['provider' => $provider], false);
    }

    public function start(Request $request, string $provider, TenantContext $tenant)
    {
        $p = $this->providers()[$provider] ?? abort(404);
        if (! $p->configured()) {
            return redirect()->route('connect.index')->withErrors(['connect' => CalendarAccounts::message('not_configured')]);
        }
        $state = bin2hex(random_bytes(24));
        $verifier = Pkce::verifier();
        $request->session()->put('calendar_oauth', ['provider' => $provider, 'state' => $state, 'verifier' => $verifier, 'tenant' => $tenant->idOrFail(), 'user' => (string) $request->user()->getAuthIdentifier(), 'expires' => time() + 600]);

        return redirect()->away($p->authUrl($this->redirectUri($provider), $state, Pkce::challenge($verifier)));
    }

    public function callback(Request $request, string $provider, TenantContext $tenant, CalendarAccounts $accounts)
    {
        $p = $this->providers()[$provider] ?? abort(404);
        $saved = $request->session()->pull('calendar_oauth');                       // one use only, whatever happens next
        $back = redirect()->route('connect.index');
        if (! is_array($saved) || $saved['provider'] !== $provider || $saved['expires'] < time() || $saved['tenant'] !== $tenant->idOrFail() || $saved['user'] !== (string) $request->user()->getAuthIdentifier()
            || ! is_string($request->query('state')) || ! hash_equals($saved['state'], $request->query('state'))) {
            return $back->withErrors(['connect' => 'The connection request has expired or is invalid. Press the button and start again.']);
        }
        if ($request->query('error') !== null || ! is_string($request->query('code')) || $request->query('code') === '') {
            return $back->withErrors(['connect' => 'The connection was cancelled or permission was not granted.']);
        }
        try {
            $t = $p->exchange((string) $request->query('code'), $saved['verifier'], $this->redirectUri($provider));
        } catch (OAuthFailure $e) {
            return $back->withErrors(['connect' => self::ERRORS[$e->getMessage()] ?? 'The connection was not completed.']);
        }
        $r = $accounts->connect($tenant->idOrFail(), (string) $request->user()->getAuthIdentifier(), $provider, $t['email'], $t['refresh_token']);

        return $r === 'ok' ? $back->with('status', $t['email'].' Connected.') : $back->withErrors(['connect' => CalendarAccounts::message($r)]);
    }
}
