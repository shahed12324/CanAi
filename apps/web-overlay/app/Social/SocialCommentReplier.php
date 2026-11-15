<?php

declare(strict_types=1);

namespace App\Social;

/**
 * Sends the approved replies.  Each reply was claimed exactly once, so it is sent at most once; an answer we cannot read means "unknown" and is failed for a person to check — never retried by itself.
 */
final class SocialCommentReplier
{
    public function __construct(private readonly SocialCommentWorkRepo $repo, private readonly TokenSealer $secrets, private readonly FacebookGraph $graph) {}

    /** @return array{sent: int, failed: int} */
    public function run(int $limit): array
    {
        $sent = 0;
        $failed = 0;
        foreach ($this->safe(fn () => $this->repo->claim($limit)) ?? [] as $c) {
            $this->send($c) ? $sent++ : $failed++;
        }

        return ['sent' => $sent, 'failed' => $failed];
    }

    /** @param  array{id: string, tenant_id: string, account_id: string, remote_id: string, reply: string}  $c */
    private function send(array $c): bool
    {
        $fail = function (string $why) use ($c): bool {
            $this->safe(fn () => $this->repo->fail($c['tenant_id'], $c['id'], $why));

            return false;
        };
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
        $res = $this->graph->replyToComment($c['remote_id'], $token, $c['reply']);
        if ($res->status === 'ok') {
            $this->safe(fn () => $this->repo->done($c['tenant_id'], $c['id'], (string) $res->id));

            return true;
        }
        if ($res->status === 'token') {
            $this->safe(fn () => $this->repo->markAccount($c['account_id'], 'invalid'));

            return $fail('Facebook The token was not accepted — connect again with a new Page token. ('.$res->message.')');
        }
        if ($res->status === 'rejected') {
            return $fail('Facebook did not take the reply, nothing went. '.$res->message);
        }

        return $fail($res->message.' Facebook Check the comment on the page; if the reply did not go "retry" give.');
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
