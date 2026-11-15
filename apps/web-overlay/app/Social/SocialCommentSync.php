<?php

declare(strict_types=1);

namespace App\Social;

/** Fetches new comments on posts the system published and stores them (once per Facebook comment).  Read-only towards Facebook; nothing is replied. */
final class SocialCommentSync
{
    public function __construct(private readonly SocialCommentWorkRepo $repo, private readonly TokenSealer $secrets, private readonly FacebookGraph $graph) {}

    /** @return array{posts: int, new: int} */
    public function run(int $limit): array
    {
        $posts = 0;
        $new = 0;
        foreach ($this->safe(fn () => $this->repo->due($limit)) ?? [] as $p) {
            $posts++;
            $cred = $this->safe(fn () => $this->repo->credentials($p['account_id']));
            if ($cred === null || $cred['tenant_id'] !== $p['tenant_id'] || $cred['status'] !== 'active') {
                continue;
            }
            $token = $this->secrets->open($cred['cipher'], $cred['version']);
            if ($token === null) {
                continue;
            }
            $r = $this->graph->comments($p['remote_id'], $token, $cred['external_id']);
            if ($r['status'] === 'token') {
                $this->safe(fn () => $this->repo->markAccount($p['account_id'], 'invalid'));

                continue;
            }
            foreach ($r['items'] as $c) {
                $res = $this->safe(fn () => $this->repo->ingest($p['tenant_id'], $p['post_id'], $c['id'], $c['author'], $c['message'], $c['at']));
                $new += $res === 'new' ? 1 : 0;
                if ($res === 'full') {
                    break;
                }
            }
        }

        return ['posts' => $posts, 'new' => $new];
    }

    /** @template T @param  \Closure(): T  $f @return T|null */
    private function safe(\Closure $f): mixed
    {
        try {
            return $f();
        } catch (\Throwable $e) {
            if (function_exists('report')) {
                report($e);
            }

            return null;
        }
    }
}
