<?php

declare(strict_types=1);

namespace App\Social;

/** What Facebook said.  ok = published; rejected = Facebook refused (nothing was posted); token = the Page token is no longer valid; unknown = we cannot tell whether it was posted. */
final class GraphResult
{
    private function __construct(public readonly string $status, public readonly ?string $id, public readonly ?string $url, public readonly string $message) {}

    public static function ok(string $id, string $url): self
    {
        return new self('ok', $id, $url, '');
    }

    public static function rejected(string $message): self
    {
        return new self('rejected', null, null, $message);
    }

    public static function token(string $message): self
    {
        return new self('token', null, null, $message);
    }

    public static function unknown(string $message): self
    {
        return new self('unknown', null, null, $message);
    }
}
