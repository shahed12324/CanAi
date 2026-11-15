<?php

declare(strict_types=1);

namespace App\Social;

/**
 * Uploads one claimed YouTube post.  The post was claimed exactly once, so this runs at most once per claim.
 * Nothing is uploaded unless every part is ready (channel, token, finished video); an upload whose outcome is unknown is failed for a person to check — never retried by itself.
 * The thumbnail is best effort: if it cannot be set the video stays up and a note says so.
 */
final class YoutubePublisher
{
    public function __construct(
        private readonly SocialPublishRepo $repo,
        private readonly TokenSealer $secrets,
        private readonly YoutubeApi $api,
        private readonly PostMedia $media,
    ) {}

    /**
     * @param  array{id: string, tenant_id: string, user_id: string, account_id: string, title: string, caption: string, media_id: string, tags: ?string, privacy: string, thumb_design_id: ?string, thumb_size: ?string}  $c
     * @return string posted|failed
     */
    public function publish(array $c): string
    {
        $fail = fn (string $why) => $this->failed($c, $why);
        if (mb_strlen($c['title']) > YoutubeMeta::TITLE_MAX || YoutubeMeta::title($c['title']) === '') {
            return $fail('YouTube The title must be 1–100 characters — change the name and get approval again. Nothing was uploaded.');
        }
        $cred = $this->safe(fn () => $this->repo->credentials($c['account_id']));
        if ($cred === null || $cred['tenant_id'] !== $c['tenant_id']) {
            return $fail('The channel connection was not found — connect again. Nothing was uploaded.');
        }
        if ($cred['status'] !== 'active') {
            return $fail('The channel connection is not active — connect again. Nothing was uploaded.');
        }
        $refresh = $this->secrets->open($cred['cipher'], $cred['version']);
        if ($refresh === null) {
            return $fail('The token could not be opened — connect the channel again. Nothing was uploaded.');
        }
        $video = $this->load($c['tenant_id'], $c['user_id'], 'video', $c['media_id'], null);
        if ($video === null || $video['kind'] !== 'video') {
            return $fail('The video was not found — nothing was uploaded. Fix the video and try again.');
        }
        $tok = $this->api->refresh($refresh);
        if ($tok['status'] === 'revoked') {
            $this->safe(fn () => $this->repo->markAccount($c['account_id'], 'invalid'));

            return $fail('Google Permission was withdrawn — connect the channel again. Nothing was uploaded.');
        }
        if ($tok['status'] !== 'ok') {
            return $fail('Google-could not be reached — nothing was uploaded. Shortly "retry" give.');
        }
        $res = $this->api->upload($tok['access'], YoutubeMeta::title($c['title']), YoutubeMeta::description($c['caption']), YoutubeMeta::tags((string) $c['tags']), $c['privacy'], $video['bytes']);
        if ($res->status === 'token') {
            $this->safe(fn () => $this->repo->markAccount($c['account_id'], 'invalid'));

            return $fail('YouTube did not accept the permission — connect the channel again. ('.$res->message.')');
        }
        if ($res->status === 'rejected') {
            return $fail('YouTube did not take the video, nothing was uploaded. '.$res->message);
        }
        if ($res->status !== 'ok') {
            return $fail($res->message.' YouTube Studio-check in; if it did not go "retry" give.');
        }
        $note = null;
        if ($c['thumb_design_id'] !== null && $c['thumb_size'] !== null) {
            $t = $this->load($c['tenant_id'], $c['user_id'], 'design', $c['thumb_design_id'], $c['thumb_size']);
            if ($t === null || $t['kind'] !== 'image') {
                $note = 'The video went, but the thumbnail image could not be drawn.';
            } else {
                $r = $this->api->thumbnail($tok['access'], (string) $res->id, $t['bytes'], $t['mime']);
                $note = $r['ok'] ? null : 'The video went. '.$r['message'];
            }
        }
        $this->safe(fn () => $this->repo->youtubeDone($c['tenant_id'], $c['id'], (string) $res->id, $res->url, $note));

        return 'posted';
    }

    /** @return ?array{kind: string, mime: string, bytes: string} */
    private function load(string $tenant, string $user, string $kind, string $id, ?string $size): ?array
    {
        try {
            return $this->media->load($tenant, $user, $kind, $id, $size);
        } catch (\Throwable $e) {
            self::report($e);

            return null;
        }
    }

    /** @param  array<string, mixed>  $c */
    private function failed(array $c, string $why): string
    {
        $this->safe(fn () => $this->repo->fail((string) $c['tenant_id'], (string) $c['id'], $why));

        return 'failed';
    }

    /** @template T @param  \Closure(): T  $f @return T|null */
    private function safe(\Closure $f): mixed
    {
        try {
            return $f();
        } catch (\Throwable $e) {
            self::report($e);

            return null;
        }
    }

    private static function report(\Throwable $e): void
    {
        if (function_exists('report')) {
            report($e);
        }
    }
}
