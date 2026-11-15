<?php

declare(strict_types=1);

namespace App\Outreach;

/** The few socket operations the IMAP reader needs; a scripted fake replaces it in tests. */
interface ImapConnection
{
    /** @throws InboxFailure inbox_unavailable when the server cannot be reached (or is not a public host) */
    public function open(string $host, int $port, bool $implicitTls): void;

    /** Upgrades the open connection to TLS (after STARTTLS was accepted). @throws InboxFailure */
    public function startTls(): void;

    /** One line without its CRLF, or null at end of stream. */
    public function line(): ?string;

    /** Exactly $n bytes (an IMAP literal), or fewer at end of stream. */
    public function read(int $n): string;

    public function write(string $data): void;

    public function close(): void;
}
