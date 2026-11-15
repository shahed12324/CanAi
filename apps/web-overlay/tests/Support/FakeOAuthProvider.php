<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Outreach\OAuthFailure;
use App\Outreach\OAuthProvider;

final class FakeOAuthProvider implements OAuthProvider
{
    public array $exchanged = [];

    public ?string $fail = null;

    public function configured(): bool
    {
        return true;
    }

    public function authUrl(string $redirectUri, string $state, string $challenge): string
    {
        return 'https://idp.test/auth?'.http_build_query(['redirect_uri' => $redirectUri, 'state' => $state, 'code_challenge' => $challenge, 'code_challenge_method' => 'S256']);
    }

    public function exchange(string $code, string $verifier, string $redirectUri): array
    {
        $this->exchanged[] = compact('code', 'verifier', 'redirectUri');
        if ($this->fail !== null) {
            throw new OAuthFailure($this->fail);
        }

        return ['refresh_token' => 'REFRESH-SECRET', 'access_token' => 'AT', 'email' => 'me@gmail.test'];
    }

    public function refresh(string $refreshToken): array
    {
        return ['access_token' => 'AT', 'refresh_token' => null];
    }
}
