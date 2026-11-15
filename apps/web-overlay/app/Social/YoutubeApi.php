<?php

declare(strict_types=1);

namespace App\Social;

use App\Outreach\HttpJson;

/**
 * Google OAuth + YouTube Data API v3 calls: connect a channel, refresh an access token, upload a video, set a thumbnail.
 * Tokens travel only in headers / form bodies (never in a URL, never logged).  Uses HttpJson so tests use a fake.
 */
final class YoutubeApi
{
    public const SCOPES = 'https://www.googleapis.com/auth/youtube.upload https://www.googleapis.com/auth/youtube.readonly';

    public const MAX_VIDEO = 100 * 1048576;

    public const MAX_THUMB = 2 * 1048576;

    public function __construct(private readonly HttpJson $http, private readonly ?string $clientId, private readonly ?string $secret) {}

    public function configured(): bool
    {
        return $this->clientId !== null && $this->clientId !== '' && $this->secret !== null && $this->secret !== '';
    }

    public function authUrl(string $redirectUri, string $state, string $challenge): string
    {
        return 'https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query([
            'client_id' => $this->clientId, 'redirect_uri' => $redirectUri, 'response_type' => 'code', 'scope' => self::SCOPES,
            'access_type' => 'offline', 'prompt' => 'consent', 'state' => $state, 'code_challenge' => $challenge, 'code_challenge_method' => 'S256',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /** @return array{status: string, refresh: string, access: string, message: string} status: ok | failed | no_refresh | scope */
    public function exchange(string $code, string $verifier, string $redirectUri): array
    {
        $bad = fn (string $s) => ['status' => $s, 'refresh' => '', 'access' => '', 'message' => ''];
        try {
            $r = $this->http->request('POST', 'https://oauth2.googleapis.com/token', [], [
                'code' => $code, 'client_id' => (string) $this->clientId, 'client_secret' => (string) $this->secret, 'redirect_uri' => $redirectUri, 'grant_type' => 'authorization_code', 'code_verifier' => $verifier,
            ]);
        } catch (\Throwable) {
            return $bad('failed');
        }
        $j = $r['json'];
        if ($r['status'] !== 200 || ! is_string($j['access_token'] ?? null)) {
            return $bad('failed');
        }
        if (! is_string($j['refresh_token'] ?? null) || $j['refresh_token'] === '') {
            return $bad('no_refresh');            // consent was skipped; connect again
        }
        if (! str_contains((string) ($j['scope'] ?? ''), 'youtube.upload')) {
            return $bad('scope');                  // the permission was unticked
        }

        return ['status' => 'ok', 'refresh' => $j['refresh_token'], 'access' => $j['access_token'], 'message' => ''];
    }

    /** @return array{status: string, access: string} status: ok | revoked | error */
    public function refresh(string $refreshToken): array
    {
        try {
            $r = $this->http->request('POST', 'https://oauth2.googleapis.com/token', [], ['client_id' => (string) $this->clientId, 'client_secret' => (string) $this->secret, 'refresh_token' => $refreshToken, 'grant_type' => 'refresh_token']);
        } catch (\Throwable) {
            return ['status' => 'error', 'access' => ''];
        }
        if (($r['json']['error'] ?? null) === 'invalid_grant') {
            return ['status' => 'revoked', 'access' => ''];
        }
        if ($r['status'] !== 200 || ! is_string($r['json']['access_token'] ?? null) || $r['json']['access_token'] === '') {
            return ['status' => 'error', 'access' => ''];
        }

        return ['status' => 'ok', 'access' => $r['json']['access_token']];
    }

    /** The signed-in person's own channel. @return array{ok: bool, id: string, name: string} */
    public function channel(string $access): array
    {
        try {
            $r = $this->http->request('GET', 'https://www.googleapis.com/youtube/v3/channels?part=snippet&mine=true', ['Authorization' => 'Bearer '.$access, 'Accept' => 'application/json']);
        } catch (\Throwable) {
            return ['ok' => false, 'id' => '', 'name' => ''];
        }
        $c = $r['json']['items'][0] ?? null;
        if ($r['status'] !== 200 || ! is_array($c) || ! is_string($c['id'] ?? null) || preg_match('/\AUC[0-9A-Za-z_-]{22}\z/', $c['id']) !== 1) {
            return ['ok' => false, 'id' => '', 'name' => ''];
        }
        $name = trim((string) preg_replace('/[\x00-\x1f\x7f]/u', ' ', (string) ($c['snippet']['title'] ?? '')));

        return ['ok' => true, 'id' => $c['id'], 'name' => mb_substr($name === '' ? 'YouTube Channels' : $name, 0, 120)];
    }

    /**
     * Uploads one video in a single multipart/related request (videos.insert, 1600 quota units).  A "private" video is the default.
     *
     * @param  list<string>  $tags
     */
    public function upload(string $access, string $title, string $description, array $tags, string $privacy, string $bytes): GraphResult
    {
        if ($bytes === '' || strlen($bytes) > self::MAX_VIDEO) {
            return GraphResult::rejected('The video size is not right (max 100 MB).');
        }
        $meta = ['snippet' => ['title' => $title, 'description' => $description, 'categoryId' => '22'], 'status' => ['privacyStatus' => in_array($privacy, ['private', 'unlisted', 'public'], true) ? $privacy : 'private']];
        if ($tags !== []) {
            $meta['snippet']['tags'] = $tags;
        }
        $b = 'jv'.bin2hex(random_bytes(8));
        $body = "--{$b}\r\nContent-Type: application/json; charset=UTF-8\r\n\r\n".json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\r\n--{$b}\r\nContent-Type: video/mp4\r\n\r\n".$bytes."\r\n--{$b}--\r\n";
        try {
            $r = $this->http->request('POST', 'https://www.googleapis.com/upload/youtube/v3/videos?uploadType=multipart&part=snippet,status', ['Authorization' => 'Bearer '.$access, 'Content-Type' => 'multipart/related; boundary='.$b, 'Accept' => 'application/json'], null, $body);
        } catch (\Throwable) {
            return GraphResult::unknown('YouTube-reply was not received — it is not known whether it was uploaded.');
        }
        $j = $r['json'];
        if ($r['status'] >= 200 && $r['status'] < 300 && ! isset($j['error'])) {
            $id = (string) ($j['id'] ?? '');
            if (preg_match('/\A[A-Za-z0-9_-]{6,20}\z/', $id) === 1) {
                return GraphResult::ok($id, 'https://www.youtube.com/watch?v='.$id);
            }

            return GraphResult::unknown('YouTube It responded but the video ID could not be understood.');
        }
        if ($r['status'] === 401) {
            return GraphResult::token('YouTube Did not accept the permission.');
        }
        if ($r['status'] >= 500 || ! isset($j['error'])) {
            return GraphResult::unknown('YouTube-has a temporary problem — it is not known whether it was uploaded.');
        }

        return GraphResult::rejected(self::reason($j));
    }

    /** Sets a custom thumbnail (50 quota units; the channel must be verified).  @return array{ok: bool, message: string} */
    public function thumbnail(string $access, string $videoId, string $bytes, string $mime): array
    {
        if ($bytes === '' || strlen($bytes) > self::MAX_THUMB || preg_match('/\A[A-Za-z0-9_-]{6,20}\z/', $videoId) !== 1) {
            return ['ok' => false, 'message' => 'The thumbnail is over 2 MB or not valid.'];
        }
        try {
            $r = $this->http->request('POST', 'https://www.googleapis.com/upload/youtube/v3/thumbnails/set?uploadType=media&videoId='.rawurlencode($videoId), ['Authorization' => 'Bearer '.$access, 'Content-Type' => $mime === 'image/jpeg' ? 'image/jpeg' : 'image/png', 'Accept' => 'application/json'], null, $bytes);
        } catch (\Throwable) {
            return ['ok' => false, 'message' => 'The thumbnail could not be sent.'];
        }
        if ($r['status'] >= 200 && $r['status'] < 300 && ! isset($r['json']['error'])) {
            return ['ok' => true, 'message' => ''];
        }

        return ['ok' => false, 'message' => 'The thumbnail was not set ('.self::reason($r['json']).') — check whether the channel is verified.'];
    }

    /** @param  array<string, mixed>  $j */
    private static function reason(array $j): string
    {
        $e = is_array($j['error'] ?? null) ? $j['error'] : [];
        $r = (string) ($e['errors'][0]['reason'] ?? '');
        $map = [
            'quotaExceeded' => 'Today’s YouTube quota is used up — try again tomorrow',
            'uploadLimitExceeded' => 'This channel’s upload limit is used up — try later',
            'forbidden' => 'The channel has no permission for this action',
            'youtubeSignupRequired' => 'This Google account has no YouTube channel',
            'invalidTitle' => 'The title is not acceptable', 'invalidDescription' => 'The description is not acceptable', 'invalidTags' => 'The tags are not acceptable',
        ];
        $msg = $map[$r] ?? trim((string) preg_replace('/[\x00-\x1f\x7f]/u', ' ', (string) ($e['message'] ?? '')));

        return 'YouTube: '.($msg === '' ? 'Unknown error' : mb_substr($msg, 0, 160)).($r !== '' && ! isset($map[$r]) ? " ({$r})" : '');
    }
}
