<?php

declare(strict_types=1);

namespace App\Outreach;

interface OAuthProvider
{
    public function configured(): bool;

    public function authUrl(string $redirectUri, string $state, string $challenge): string;

    /** @return array{refresh_token: string, access_token: string, email: string} */
    public function exchange(string $code, string $verifier, string $redirectUri): array;

    /**
     * @return array{access_token: string, refresh_token: ?string} refresh_token is set only when the provider rotated it
     *
     * @throws OAuthRevoked
     * @throws OAuthFailure
     */
    public function refresh(string $refreshToken): array;
}
