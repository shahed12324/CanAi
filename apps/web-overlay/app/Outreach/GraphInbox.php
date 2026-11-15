<?php

declare(strict_types=1);

namespace App\Outreach;

final class GraphInbox implements InboxReader
{
    private const ORIGIN = 'https://graph.microsoft.com/';

    public function __construct(private readonly HttpJson $http, private readonly int $max = 60) {}

    public function fetch(array $conn, string $sinceIso, callable $isSeen): array
    {
        $auth = ['Authorization' => 'Bearer '.$conn['access_token']];
        $since = gmdate('Y-m-d\TH:i:s\Z', max(0, (int) strtotime($sinceIso)));
        $url = self::ORIGIN.'v1.0/me/mailFolders/inbox/messages?'.http_build_query([
            '$filter' => "receivedDateTime ge {$since}", '$orderby' => 'receivedDateTime asc', '$top' => 50,
            '$select' => 'id,subject,from,receivedDateTime,conversationId,internetMessageHeaders,bodyPreview',
        ], '', '&', PHP_QUERY_RFC3986);
        $out = [];
        for ($i = 0; $i < 3 && $url !== null && count($out) < $this->max; $i++) {
            $r = $this->http->request('GET', $url, $auth);
            if ($r['status'] === 401) {
                throw new InboxFailure('oauth_revoked');
            }
            if ($r['status'] === 403) {
                throw new InboxFailure('inbox_scope');
            }
            if ($r['status'] !== 200) {
                throw new InboxFailure('inbox_unavailable');
            }
            foreach ((array) ($r['json']['value'] ?? []) as $m) {
                if (! is_array($m) || ! is_string($m['id'] ?? null) || $isSeen($m['id'])) {
                    continue;
                }
                $h = [];
                foreach ((array) ($m['internetMessageHeaders'] ?? []) as $x) {
                    if (isset($x['name'], $x['value'])) {
                        $h[strtolower((string) $x['name'])] = (string) $x['value'];
                    }
                }
                $out[] = [
                    'id' => $m['id'], 'from' => strtolower((string) ($m['from']['emailAddress']['address'] ?? '')), 'subject' => (string) ($m['subject'] ?? ''),
                    'thread_id' => isset($m['conversationId']) ? (string) $m['conversationId'] : null, 'in_reply_to' => $h['in-reply-to'] ?? null, 'content_type' => $h['content-type'] ?? null,
                    'auto_submitted' => $h['auto-submitted'] ?? null, 'precedence' => $h['precedence'] ?? null, 'x_auto_response_suppress' => $h['x-auto-response-suppress'] ?? null,
                    'x_failed_recipients' => $h['x-failed-recipients'] ?? null, 'text' => (string) ($m['bodyPreview'] ?? ''), 'received_at' => isset($m['receivedDateTime']) ? (string) $m['receivedDateTime'] : null,
                ];
            }
            $next = $r['json']['@odata.nextLink'] ?? null;
            $url = is_string($next) && str_starts_with($next, self::ORIGIN) ? $next : null;      // never send the token to another host
        }

        return array_slice($out, 0, $this->max);
    }
}
