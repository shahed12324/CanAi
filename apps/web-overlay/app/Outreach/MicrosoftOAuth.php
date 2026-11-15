<?php

declare(strict_types=1);

namespace App\Outreach;

final class MicrosoftOAuth implements OAuthProvider
{
    public const SCOPES = 'offline_access User.Read Mail.Send Mail.Read';

    public function __construct(private readonly HttpJson $http, private readonly ?string $clientId, private readonly ?string $secret, private readonly string $tenant = 'common')
    {
        if (preg_match('/\A[A-Za-z0-9.-]{1,64}\z/', $tenant) !== 1) {
            throw new \InvalidArgumentException('bad tenant');
        }
    }

    public function configured(): bool
    {
        return $this->clientId !== null && $this->clientId !== '' && $this->secret !== null && $this->secret !== '';
    }

    private function base(): string
    {
        return 'https://login.microsoftonline.com/'.$this->tenant.'/oauth2/v2.0/';
    }

    public function authUrl(string $redirectUri, string $state, string $challenge): string
    {
        return $this->base().'authorize?'.http_build_query([
            'client_id' => $this->clientId, 'response_type' => 'code', 'redirect_uri' => $redirectUri, 'response_mode' => 'query',
            'scope' => self::SCOPES, 'state' => $state, 'code_challenge' => $challenge, 'code_challenge_method' => 'S256', 'prompt' => 'select_account',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public function exchange(string $code, string $verifier, string $redirectUri): array
    {
        $r = $this->http->request('POST', $this->base().'token', [], [
            'client_id' => (string) $this->clientId, 'client_secret' => (string) $this->secret, 'code' => $code, 'redirect_uri' => $redirectUri,
            'grant_type' => 'authorization_code', 'code_verifier' => $verifier, 'scope' => self::SCOPES,
        ]);
        $j = $r['json'];
        if ($r['status'] !== 200 || ! is_string($j['access_token'] ?? null)) {
            throw new OAuthFailure('microsoft_exchange_failed');
        }
        if (! is_string($j['refresh_token'] ?? null) || $j['refresh_token'] === '') {
            throw new OAuthFailure('microsoft_no_refresh_token');
        }
        if (! str_contains(strtolower((string) ($j['scope'] ?? '')), 'mail.send')) {
            throw new OAuthFailure('microsoft_scope_missing');
        }
        $u = $this->http->request('GET', 'https://graph.microsoft.com/v1.0/me?$select=mail,userPrincipalName', ['Authorization' => 'Bearer '.$j['access_token']]);
        $email = strtolower((string) ($u['json']['mail'] ?? $u['json']['userPrincipalName'] ?? ''));
        if ($u['status'] !== 200 || $email === '' || ! str_contains($email, '@')) {
            throw new OAuthFailure('microsoft_email_unknown');
        }

        return ['refresh_token' => $j['refresh_token'], 'access_token' => $j['access_token'], 'email' => $email];
    }

    public function refresh(string $refreshToken): array
    {
        $r = $this->http->request('POST', $this->base().'token', [], [
            'client_id' => (string) $this->clientId, 'client_secret' => (string) $this->secret, 'refresh_token' => $refreshToken, 'grant_type' => 'refresh_token', 'scope' => self::SCOPES,
        ]);
        if (in_array($r['json']['error'] ?? null, ['invalid_grant', 'interaction_required'], true)) {
            throw new OAuthRevoked('microsoft_revoked');
        }
        if ($r['status'] !== 200 || ! is_string($r['json']['access_token'] ?? null)) {
            throw new OAuthFailure('microsoft_refresh_failed');
        }

        return ['access_token' => $r['json']['access_token'], 'refresh_token' => is_string($r['json']['refresh_token'] ?? null) ? $r['json']['refresh_token'] : null];
    }
}
