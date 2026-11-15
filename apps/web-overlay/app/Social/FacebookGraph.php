<?php

declare(strict_types=1);

namespace App\Social;

use App\Outreach\HttpJson;

/**
 * Facebook Graph API calls for a Page: verify a token, publish a text post, a photo or a video.
 * The token travels only in the request (never logged — the HTTP wrapper logs nothing).  Pure of Laravel except through HttpJson, so tests use a fake.
 */
final class FacebookGraph
{
    public const MAX_PHOTO = 10 * 1048576;

    public const MAX_VIDEO = 100 * 1048576;

    public function __construct(private readonly HttpJson $http, private readonly string $version = 'v24.0') {}

    private function url(string $host, string $path): string
    {
        return "https://{$host}/{$this->version}/{$path}";
    }

    /** @return array{ok: bool, id: string, name: string, token: bool, message: string} */
    public function page(string $pageId, string $token): array
    {
        try {
            $r = $this->http->request('GET', $this->url('graph.facebook.com', rawurlencode($pageId)).'?fields=id,name&access_token='.rawurlencode($token));
        } catch (\Throwable) {
            return ['ok' => false, 'id' => '', 'name' => '', 'token' => false, 'message' => 'Facebook-could not be reached.'];
        }
        $j = $r['json'];
        if ($r['status'] === 200 && is_string($j['id'] ?? null) && is_string($j['name'] ?? null)) {
            return ['ok' => true, 'id' => $j['id'], 'name' => $j['name'], 'token' => false, 'message' => ''];
        }

        return ['ok' => false, 'id' => '', 'name' => '', 'token' => self::isTokenError($j), 'message' => self::errorText($j)];
    }

    /**
     * Reactions, comments and shares of a published post (stable Graph fields) and, when Facebook offers it, a reach / views number (best effort — Facebook renames these metrics).
     *
     * @return array{status: string, reactions: ?int, comments: ?int, shares: ?int, reach: ?int} status: ok | token | error
     */
    public function stats(string $remoteId, string $token, string $mediaKind): array
    {
        $bad = fn (string $s) => ['status' => $s, 'reactions' => null, 'comments' => null, 'shares' => null, 'reach' => null];
        if (preg_match('/\A[0-9_]{3,100}\z/', $remoteId) !== 1) {
            return $bad('error');
        }
        $fields = 'reactions.summary(true).limit(0),comments.summary(true).limit(0)'.($mediaKind === 'video' ? '' : ',shares');
        try {
            $r = $this->http->request('GET', $this->url('graph.facebook.com', $remoteId).'?fields='.rawurlencode($fields).'&access_token='.rawurlencode($token));
        } catch (\Throwable) {
            return $bad('error');
        }
        $j = $r['json'];
        if ($r['status'] !== 200 || isset($j['error'])) {
            return $bad(self::isTokenError($j) ? 'token' : 'error');
        }
        $n = fn ($v) => is_int($v) || (is_string($v) && ctype_digit($v)) ? max(0, (int) $v) : null;
        $reactions = $n($j['reactions']['summary']['total_count'] ?? null);
        if ($reactions === null) {
            return $bad('error');
        }
        $reach = null;
        if ($mediaKind !== 'video') {
            foreach (['post_media_view', 'post_impressions_unique'] as $metric) {
                try {
                    $x = $this->http->request('GET', $this->url('graph.facebook.com', $remoteId.'/insights').'?metric='.$metric.'&access_token='.rawurlencode($token));
                } catch (\Throwable) {
                    break;
                }
                $v = $x['status'] === 200 ? ($x['json']['data'][0]['values'][0]['value'] ?? null) : null;
                if ($n($v) !== null) {
                    $reach = $n($v);
                    break;
                }
            }
        }

        return ['status' => 'ok', 'reactions' => $reactions, 'comments' => $n($j['comments']['summary']['total_count'] ?? null) ?? 0, 'shares' => $mediaKind === 'video' ? null : ($n($j['shares']['count'] ?? null) ?? 0), 'reach' => $reach];
    }

    /**
     * Top-level comments on a published post, newest first (needs pages_read_engagement).  Comments written by the Page itself and comments without text are left out.
     *
     * @return array{status: string, items: list<array{id: string, message: string, author: string, at: string}>} status: ok | token | error
     */
    public function comments(string $postRemoteId, string $token, string $pageId): array
    {
        if (preg_match('/\A[0-9_]{3,100}\z/', $postRemoteId) !== 1) {
            return ['status' => 'error', 'items' => []];
        }
        try {
            $r = $this->http->request('GET', $this->url('graph.facebook.com', $postRemoteId.'/comments').'?fields='.rawurlencode('id,message,created_time,from{id,name}').'&filter=toplevel&order=reverse_chronological&limit=50&access_token='.rawurlencode($token));
        } catch (\Throwable) {
            return ['status' => 'error', 'items' => []];
        }
        $j = $r['json'];
        if ($r['status'] !== 200 || isset($j['error']) || ! is_array($j['data'] ?? null)) {
            return ['status' => self::isTokenError($j) ? 'token' : 'error', 'items' => []];
        }
        $items = [];
        foreach ($j['data'] as $c) {
            if (! is_array($c) || ! is_string($c['id'] ?? null) || preg_match('/\A[0-9_]{3,100}\z/', $c['id']) !== 1 || ! is_string($c['message'] ?? null)) {
                continue;
            }
            $msg = trim((string) preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/u', '', $c['message']));
            $from = is_array($c['from'] ?? null) ? $c['from'] : [];
            if ($msg === '' || (string) ($from['id'] ?? '') === $pageId) {
                continue;
            }
            $at = strtotime((string) ($c['created_time'] ?? ''));
            $items[] = ['id' => $c['id'], 'message' => mb_substr($msg, 0, 2000), 'author' => mb_substr(trim((string) preg_replace('/[\x00-\x1f\x7f]/u', ' ', (string) ($from['name'] ?? ''))), 0, 200), 'at' => gmdate('Y-m-d H:i:s+00', $at === false ? time() : $at)];
        }

        return ['status' => 'ok', 'items' => $items];
    }

    /** Replies to one comment as the Page (needs pages_manage_engagement).  The caller guarantees this runs at most once per approved reply. */
    public function replyToComment(string $commentId, string $token, string $message): GraphResult
    {
        if (preg_match('/\A[0-9_]{3,100}\z/', $commentId) !== 1 || trim($message) === '' || mb_strlen($message) > 1000) {
            return GraphResult::rejected('The reply cannot be sent (the comment ID or text is not valid).');
        }

        return $this->send($this->url('graph.facebook.com', $commentId.'/comments'), ['Accept' => 'application/json'], ['message' => $message, 'access_token' => $token], null, '');
    }

    public function publishText(string $pageId, string $token, string $message): GraphResult
    {
        return $this->send($this->url('graph.facebook.com', rawurlencode($pageId).'/feed'), ['Accept' => 'application/json'], ['message' => $message, 'access_token' => $token], null, $pageId);
    }

    public function publishPhoto(string $pageId, string $token, string $caption, string $bytes, string $mime): GraphResult
    {
        if ($bytes === '' || strlen($bytes) > self::MAX_PHOTO) {
            return GraphResult::rejected('The image size is not right (max 10 MB).');
        }
        $ext = $mime === 'image/jpeg' ? 'jpg' : 'png';
        $m = Multipart::build(['caption' => $caption, 'access_token' => $token, 'published' => 'true'], 'source', "post.{$ext}", $mime === 'image/jpeg' ? 'image/jpeg' : 'image/png', $bytes);

        return $this->send($this->url('graph.facebook.com', rawurlencode($pageId).'/photos'), ['Content-Type' => $m['contentType'], 'Accept' => 'application/json'], null, $m['body'], $pageId);
    }

    public function publishVideo(string $pageId, string $token, string $description, string $bytes): GraphResult
    {
        if ($bytes === '' || strlen($bytes) > self::MAX_VIDEO) {
            return GraphResult::rejected('The video size is not right (max 100 MB).');
        }
        $m = Multipart::build(['description' => $description, 'access_token' => $token], 'source', 'video.mp4', 'video/mp4', $bytes);

        return $this->send($this->url('graph-video.facebook.com', rawurlencode($pageId).'/videos'), ['Content-Type' => $m['contentType'], 'Accept' => 'application/json'], null, $m['body'], $pageId, true);
    }

    /** @param  array<string, string>  $headers @param  array<string, string>|null  $form */
    private function send(string $url, array $headers, ?array $form, ?string $body, string $pageId, bool $video = false): GraphResult
    {
        try {
            $r = $this->http->request('POST', $url, $headers, $form, $body);
        } catch (\Throwable) {
            return GraphResult::unknown('Facebook-reply was not received — it is not known whether the post went.');   // may have been sent
        }
        $j = $r['json'];
        if ($r['status'] >= 200 && $r['status'] < 300 && ! isset($j['error'])) {
            $id = (string) ($j['post_id'] ?? $j['id'] ?? '');
            if ($id !== '' && preg_match('/\A[0-9_]{3,100}\z/', $id) === 1) {
                return GraphResult::ok($id, $video ? 'https://www.facebook.com/watch/?v='.$id : 'https://www.facebook.com/'.$id);
            }

            return GraphResult::unknown('Facebook It responded but the post ID could not be understood.');
        }
        if (self::isTokenError($j)) {
            return GraphResult::token(self::errorText($j));
        }
        if ($r['status'] >= 500 || ! isset($j['error']) || in_array((int) ($j['error']['code'] ?? 0), [1, 2], true)) {
            return GraphResult::unknown('Facebook-has a temporary problem — it is not known whether the post went.');
        }

        return GraphResult::rejected(self::errorText($j));
    }

    /** @param  array<string, mixed>  $j */
    private static function isTokenError(array $j): bool
    {
        $e = $j['error'] ?? null;

        return is_array($e) && (in_array((int) ($e['code'] ?? 0), [102, 190], true) || ($e['type'] ?? '') === 'OAuthException' && in_array((int) ($e['code'] ?? 0), [10, 200], true));
    }

    /** @param  array<string, mixed>  $j */
    private static function errorText(array $j): string
    {
        $e = $j['error'] ?? null;
        $m = is_array($e) ? (string) ($e['message'] ?? '') : '';
        $m = trim((string) preg_replace('/[\x00-\x1f\x7f]/u', ' ', $m));
        $code = is_array($e) ? (int) ($e['code'] ?? 0) : 0;

        return 'Facebook: '.($m === '' ? 'Unknown error' : mb_substr($m, 0, 200)).($code > 0 ? " (Code {$code})" : '');
    }
}
