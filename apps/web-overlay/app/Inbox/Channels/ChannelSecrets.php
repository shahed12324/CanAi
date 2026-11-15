<?php

declare(strict_types=1);

namespace App\Inbox\Channels;

use App\Ai\CredentialVault;
use App\Inbox\InboxRepo;

/** Seals channel secrets with the platform vault and opens them only in memory. */
final class ChannelSecrets
{
    public function __construct(private readonly CredentialVault $vault, private readonly InboxRepo $repo) {}

    /** @param array<string, string> $secrets @return array{cipher: string, version: int} */
    public function seal(array $secrets): array
    {
        return $this->vault->encrypt(json_encode($secrets, JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed>|null null when missing or undecryptable */
    public function open(string $channelId): ?array
    {
        $c = $this->repo->credentials($channelId);
        if ($c === null) {
            return null;
        }
        try {
            $a = json_decode($this->vault->decrypt($c['cipher'], $c['version']), true, 8, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return null;
        }

        return is_array($a) ? $a : null;
    }
}
