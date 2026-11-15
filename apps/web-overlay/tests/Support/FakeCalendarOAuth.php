<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Outreach\OAuthFailure;
use App\Outreach\OAuthProvider;
use App\Outreach\OAuthRevoked;

final class FakeCalendarOAuth implements OAuthProvider
{
    public bool $configured = true;

    public ?string $rotate = null;            // a new refresh token the provider hands back

    public ?string $refreshFails = null;      // revoked | down

    /** @var list<string> */
    public array $refreshed = [];

    public function configured(): bool
    {
        return $this->configured;
    }

    public function authUrl(string $redirectUri, string $state, string $challenge): string
    {
        return 'https://idp.test/auth';
    }

    public function exchange(string $code, string $verifier, string $redirectUri): array
    {
        throw new OAuthFailure('unused');
    }

    public function refresh(string $refreshToken): array
    {
        $this->refreshed[] = $refreshToken;
        if ($this->refreshFails === 'revoked') {
            throw new OAuthRevoked('revoked');
        }
        if ($this->refreshFails === 'down') {
            throw new OAuthFailure('down');
        }

        return ['access_token' => 'AT-'.$refreshToken, 'refresh_token' => $this->rotate];
    }
}
