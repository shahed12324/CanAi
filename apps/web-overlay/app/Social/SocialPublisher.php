<?php

declare(strict_types=1);

namespace App\Social;

/**
 * Publishes one claimed post to its Facebook Page and records the outcome.  The post was claimed exactly once, so this runs at most once per claim.
 * Nothing is posted unless every part (token, picture / video) is ready — a post that should carry a picture is never silently posted as text only.
 */
final class SocialPublisher
{
    public function __construct(
        private readonly SocialPublishRepo $repo,
        private readonly TokenSealer $secrets,
        private readonly FacebookGraph $graph,
        private readonly PostMedia $media,
    ) {}

    /**
     * @param  array{id: string, tenant_id: string, user_id: string, account_id: string, caption: string, media_kind: string, media_id: ?string, media_size: ?string}  $c
     * @return string posted|failed
     */
    public function publish(array $c): string
    {
        $fail = fn (string $why) => $this->failed($c, $why);
        $cred = $this->safe(fn () => $this->repo->credentials($c['account_id']));
        if ($cred === null || $cred['tenant_id'] !== $c['tenant_id']) {
            return $fail('The page connection was not found — connect again.');
        }
        if ($cred['status'] !== 'active') {
            return $fail('The page connection is not active — connect again with a new token.');
        }
        $token = $this->secrets->open($cred['cipher'], $cred['version']);
        if ($token === null) {
            return $fail('The token could not be opened — connect again.');
        }
        $m = null;
        if ($c['media_kind'] !== 'none') {
            try {
                $m = $c['media_id'] === null ? null : $this->media->load($c['tenant_id'], $c['user_id'], $c['media_kind'], $c['media_id'], $c['media_size']);
            } catch (\Throwable $e) {
                self::report($e);
                $m = null;
            }
            if ($m === null) {
                return $fail('The image/video was not found or could not be drawn — nothing was posted. Fix the media and try again.');
            }
        }
        $page = $cred['external_id'];
        $res = match (true) {
            $m === null => $this->graph->publishText($page, $token, $c['caption']),
            $m['kind'] === 'video' => $this->graph->publishVideo($page, $token, $c['caption'], $m['bytes']),
            default => $this->graph->publishPhoto($page, $token, $c['caption'], $m['bytes'], $m['mime']),
        };
        if ($res->status === 'ok') {
            $this->safe(fn () => $this->repo->done($c['tenant_id'], $c['id'], (string) $res->id, $res->url));

            return 'posted';
        }
        if ($res->status === 'token') {
            $this->safe(fn () => $this->repo->markAccount($c['account_id'], 'invalid'));

            return $fail('Facebook The token was not accepted — connect again with a new Page token. ('.$res->message.')');
        }
        if ($res->status === 'rejected') {
            return $fail('Facebook did not take the post, nothing was posted. '.$res->message);
        }

        return $fail($res->message.' Facebook Check on the page; if it did not go "retry" give.');
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
