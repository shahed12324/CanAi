<?php

declare(strict_types=1);

namespace App\Outreach;

final class Pkce
{
    public static function verifier(): string
    {
        return Mime::base64Url(random_bytes(48));          // 64 URL-safe characters, within the 43-128 allowed
    }

    public static function challenge(string $verifier): string
    {
        return Mime::base64Url(hash('sha256', $verifier, true));
    }
}
