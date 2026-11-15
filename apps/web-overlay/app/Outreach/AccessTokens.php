<?php

declare(strict_types=1);

namespace App\Outreach;

use Closure;

/** Turns a stored (encrypted) refresh token into a short-lived access token, and keeps a rotated refresh token. */
final class AccessTokens
{
    /**
     * @param  array<string, OAuthProvider>  $providers  'gmail' | 'graph'
     * @param  Closure(string, int): string  $decrypt
     * @param  Closure(string): array{cipher: string, version: int}  $encrypt
     */
    public function __construct(private readonly array $providers, private readonly OutreachRepo $repo, private readonly Closure $decrypt, private readonly Closure $encrypt) {}

    /** @param array{id: string, provider: string, refresh_ct: ?string, key_version: int} $account */
    public function forAccount(array $account): string
    {
        $p = $this->providers[$account['provider']] ?? null;
        if ($p === null || ! $p->configured() || $account['refresh_ct'] === null) {
            $this->repo->setAccountError($account['id'], 'oauth_not_configured');
            throw new TransportFailure('oauth_not_configured', false);
        }
        try {
            $refresh = ($this->decrypt)($account['refresh_ct'], $account['key_version']);
            $t = $p->refresh($refresh);
        } catch (OAuthRevoked) {
            $this->repo->setAccountError($account['id'], 'oauth_revoked');
            throw new TransportFailure('oauth_revoked', false);
        } catch (OAuthFailure|\RuntimeException) {
            throw new TransportFailure('oauth_unavailable', false);          // a passing outage: no error flag on the account
        }
        if ($t['refresh_token'] !== null && $t['refresh_token'] !== $refresh) {
            $e = ($this->encrypt)($t['refresh_token']);
            $this->repo->saveRefreshToken($account['id'], $e['cipher'], $e['version']);
        }
        $this->repo->setAccountError($account['id'], null);

        return $t['access_token'];
    }
}
