<?php

declare(strict_types=1);

namespace App\Outreach;

interface InboxReader
{
    /**
     * Mails that arrived at or after $sinceIso, skipping ids for which $isSeen returns true (so they cost no extra calls).
     *
     * @param  array<string, mixed>  $conn  Gmail / Graph: the access token; IMAP: host, port, security, username, password
     * @param  callable(string): bool  $isSeen
     * @return list<array{id: string, from: string, subject: string, thread_id: ?string, in_reply_to: ?string, content_type: ?string, auto_submitted: ?string, precedence: ?string, x_auto_response_suppress: ?string, x_failed_recipients: ?string, text: string, received_at: ?string}>
     *
     * @throws InboxFailure
     */
    public function fetch(array $conn, string $sinceIso, callable $isSeen): array;
}
