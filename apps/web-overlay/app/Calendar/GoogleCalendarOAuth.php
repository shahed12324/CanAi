<?php

declare(strict_types=1);

namespace App\Calendar;

use App\Outreach\HttpJson;
use App\Outreach\OAuthFailure;
use App\Outreach\OAuthProvider;
use App\Outreach\OAuthRevoked;

/** Same Google OAuth client as the e-mail connection, asked for calendar permission only (events: read + write; nothing about mail). */
final class GoogleCalendarOAuth implements OAuthProvider
{
    public const SCOPES = 'openid email https://www.googleapis.com/auth/calendar.events';

    public function __construct(private readonly HttpJson $http, private readonly ?string $clientId, private readonly ?string $secret) {}

    public function configured(): bool
    {
        return $this->clientId !== null && $this->clientId !== '' && $this->secret !== null && $this->secret !== '';
    }

    public function authUrl(string $redirectUri, string $state, string $challenge): string
    {
        return 'https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query([
            'client_id' => $this->clientId, 'redirect_uri' => $redirectUri, 'response_type' => 'code', 'scope' => self::SCOPES,
            'access_type' => 'offline', 'prompt' => 'consent', 'state' => $state, 'code_challenge' => $challenge, 'code_challenge_method' => 'S256',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public function exchange(string $code, string $verifier, string $redirectUri): array
    {
        $r = $this->http->request('POST', 'https://oauth2.googleapis.com/token', [], [
            'code' => $code, 'client_id' => (string) $this->clientId, 'client_secret' => (string) $this->secret,
            'redirect_uri' => $redirectUri, 'grant_type' => 'authorization_code', 'code_verifier' => $verifier,
        ]);
        $j = $r['json'];
        if ($r['status'] !== 200 || ! is_string($j['access_token'] ?? null)) {
            throw new OAuthFailure('google_exchange_failed');
        }
        if (! is_string($j['refresh_token'] ?? null) || $j['refresh_token'] === '') {
            throw new OAuthFailure('google_no_refresh_token');
        }
        if (! str_contains((string) ($j['scope'] ?? ''), 'calendar.events')) {
            throw new OAuthFailure('google_scope_missing');
        }
        $u = $this->http->request('GET', 'https://openidconnect.googleapis.com/v1/userinfo', ['Authorization' => 'Bearer '.$j['access_token']]);
        $email = strtolower((string) ($u['json']['email'] ?? ''));
        if ($u['status'] !== 200 || $email === '' || ($u['json']['email_verified'] ?? false) !== true) {
            throw new OAuthFailure('google_email_unverified');
        }

        return ['refresh_token' => $j['refresh_token'], 'access_token' => $j['access_token'], 'email' => $email];
    }

    public function refresh(string $refreshToken): array
    {
        $r = $this->http->request('POST', 'https://oauth2.googleapis.com/token', [], [
            'client_id' => (string) $this->clientId, 'client_secret' => (string) $this->secret, 'refresh_token' => $refreshToken, 'grant_type' => 'refresh_token',
        ]);
        if (($r['json']['error'] ?? null) === 'invalid_grant') {
            throw new OAuthRevoked('google_revoked');
        }
        if ($r['status'] !== 200 || ! is_string($r['json']['access_token'] ?? null)) {
            throw new OAuthFailure('google_refresh_failed');
        }

        return ['access_token' => $r['json']['access_token'], 'refresh_token' => is_string($r['json']['refresh_token'] ?? null) ? $r['json']['refresh_token'] : null];
    }
}
