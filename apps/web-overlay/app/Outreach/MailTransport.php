<?php

declare(strict_types=1);

namespace App\Outreach;

interface MailTransport
{
    /**
     * @param  array{type?: string, host?: string, port?: int, security?: string, username?: string, password?: string, access_token?: string}  $conn  smtp login, or the OAuth access token of a Gmail / Graph account
     * @param  array{from_email: string, from_name: ?string, to: string, subject: string, body: string, headers: array<string, string>, attachments?: list<array{name: string, mime: string, bytes: string}>}  $mail
     * @return array{message_id: string, provider_id: ?string, thread_id: ?string} the Message-ID that was used, and the provider's own ids when it gives them
     *
     * @throws TransportFailure
     */
    public function send(array $conn, array $mail): array;
}
