<?php

declare(strict_types=1);

namespace App\Outreach;

/** users.messages.send with a raw RFC 5322 message. The access token lives only in memory for this request. */
final class GmailTransport
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
            $r = $this->http->request('POST', 'https://gmail.googleapis.com/gmail/v1/users/me/messages/send',
                ['Authorization' => 'Bearer '.$conn['access_token'], 'Content-Type' => 'application/json'], null, json_encode(['raw' => Mime::base64Url($raw)], JSON_THROW_ON_ERROR));
        } catch (\Throwable) {
            throw new TransportFailure('api_unavailable', false);
        }
        if ($r['status'] !== 200) {
            throw ApiErrors::failure($r['status'], $r['json']);
        }

        return ['message_id' => $id, 'provider_id' => isset($r['json']['id']) ? (string) $r['json']['id'] : null, 'thread_id' => isset($r['json']['threadId']) ? (string) $r['json']['threadId'] : null];
    }
}
