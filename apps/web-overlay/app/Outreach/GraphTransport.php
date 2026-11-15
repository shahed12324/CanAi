<?php

declare(strict_types=1);

namespace App\Outreach;

/** POST /me/sendMail with the whole message as base64 MIME (text/plain), so our List-Unsubscribe headers are kept. */
final class GraphTransport
{
    public function __construct(private readonly HttpJson $http) {}

    /** @return array{message_id: string, provider_id: ?string, thread_id: ?string} */
    public function send(array $conn, array $mail): array
    {
        $id = bin2hex(random_bytes(12)).'@'.(substr(strrchr($mail['from_email'], '@') ?: '@localhost', 1));
        try {
            $raw = Mime::message($mail, $id, gmdate('D, d M Y H:i:s +0000'));
        } catch (\InvalidArgumentException) {
            throw new TransportFailure('api_rejected', true);
        }
        try {
            $r = $this->http->request('POST', 'https://graph.microsoft.com/v1.0/me/sendMail', ['Authorization' => 'Bearer '.$conn['access_token'], 'Content-Type' => 'text/plain'], null, base64_encode($raw));
        } catch (\Throwable) {
            throw new TransportFailure('api_unavailable', false);
        }
        if ($r['status'] !== 202) {
            throw ApiErrors::failure($r['status'], $r['json']);
        }

        return ['message_id' => $id, 'provider_id' => null, 'thread_id' => null];            // Graph answers 202 with no id
    }
}
