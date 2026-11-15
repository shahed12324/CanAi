<?php

declare(strict_types=1);

namespace App\Outreach;

/** Looks only at message metadata and the short snippet (never downloads full bodies or attachments). */
final class GmailInbox implements InboxReader
{
    private const BASE = 'https://gmail.googleapis.com/gmail/v1/users/me/messages';

    private const HEADERS = ['From', 'Subject', 'In-Reply-To', 'Content-Type', 'Auto-Submitted', 'Precedence', 'X-Auto-Response-Suppress', 'X-Failed-Recipients'];

    public function __construct(private readonly HttpJson $http, private readonly int $max = 60) {}

    public function fetch(array $conn, string $sinceIso, callable $isSeen): array
    {
        $auth = ['Authorization' => 'Bearer '.$conn['access_token']];
        $after = max(0, (int) strtotime($sinceIso));
        $ids = [];
        $page = null;
        for ($i = 0; $i < 3 && count($ids) < $this->max; $i++) {
            $url = self::BASE.'?'.http_build_query(['q' => "in:inbox after:{$after}", 'maxResults' => 50] + ($page !== null ? ['pageToken' => $page] : []), '', '&', PHP_QUERY_RFC3986);
            $r = $this->http->request('GET', $url, $auth);
            self::check($r);
            foreach ((array) ($r['json']['messages'] ?? []) as $m) {
                if (isset($m['id']) && is_string($m['id']) && ! $isSeen($m['id'])) {
                    $ids[] = $m['id'];
                }
            }
            $page = is_string($r['json']['nextPageToken'] ?? null) ? $r['json']['nextPageToken'] : null;
            if ($page === null) {
                break;
            }
        }
        $out = [];
        foreach (array_slice($ids, 0, $this->max) as $id) {
            $q = http_build_query(['format' => 'metadata'], '', '&').'&'.implode('&', array_map(fn ($h) => 'metadataHeaders='.rawurlencode($h), self::HEADERS));
            $r = $this->http->request('GET', self::BASE.'/'.rawurlencode($id).'?'.$q, $auth);
            if ($r['status'] === 404) {
                continue;                                              // deleted between the two calls
            }
            self::check($r);
            $h = [];
            foreach ((array) ($r['json']['payload']['headers'] ?? []) as $x) {
                if (isset($x['name'], $x['value'])) {
                    $h[strtolower((string) $x['name'])] = (string) $x['value'];
                }
            }
            $out[] = [
                'id' => $id, 'from' => self::email($h['from'] ?? ''), 'subject' => $h['subject'] ?? '', 'thread_id' => isset($r['json']['threadId']) ? (string) $r['json']['threadId'] : null,
                'in_reply_to' => $h['in-reply-to'] ?? null, 'content_type' => $h['content-type'] ?? null, 'auto_submitted' => $h['auto-submitted'] ?? null, 'precedence' => $h['precedence'] ?? null,
                'x_auto_response_suppress' => $h['x-auto-response-suppress'] ?? null, 'x_failed_recipients' => $h['x-failed-recipients'] ?? null,
                'text' => html_entity_decode((string) ($r['json']['snippet'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                'received_at' => isset($r['json']['internalDate']) ? gmdate('c', intdiv((int) $r['json']['internalDate'], 1000)) : null,
            ];
        }

        return $out;
    }

    /** @param array{status: int, json: array<string, mixed>} $r */
    private static function check(array $r): void
    {
        if ($r['status'] === 403 || $r['status'] === 401) {
            throw new InboxFailure($r['status'] === 401 ? 'oauth_revoked' : 'inbox_scope');
        }
        if ($r['status'] !== 200) {
            throw new InboxFailure('inbox_unavailable');
        }
    }

    public static function email(string $from): string
    {
        return preg_match('/<([^>]+)>/', $from, $m) === 1 ? strtolower(trim($m[1])) : strtolower(trim($from));
    }
}
