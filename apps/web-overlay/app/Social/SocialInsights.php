<?php

declare(strict_types=1);

namespace App\Social;

/** Looks up the numbers of posts the system published and stores a snapshot for each.  A failed look is stored as an empty snapshot so it is not repeated at once. */
final class SocialInsights
{
    public function __construct(private readonly SocialPublishRepo $repo, private readonly TokenSealer $secrets, private readonly FacebookGraph $graph) {}

    /** @return array{looked: int, saved: int} */
    public function run(int $limit): array
    {
        $looked = 0;
        $saved = 0;
        foreach ($this->safe(fn () => $this->repo->statsDue($limit)) ?? [] as $p) {
            $looked++;
            $s = $this->look($p);
            $ok = $s['status'] === 'ok';
            $saved += $this->safe(fn () => $this->repo->saveStats($p['tenant_id'], $p['id'], $ok ? $s['reactions'] : null, $ok ? $s['comments'] : null, $ok ? $s['shares'] : null, $ok ? $s['reach'] : null)) === 'ok' && $ok ? 1 : 0;
        }

        return ['looked' => $looked, 'saved' => $saved];
    }

    /**
     * @param  array{id: string, tenant_id: string, account_id: string, remote_id: string, media_kind: string}  $p
     * @return array{status: string, reactions: ?int, comments: ?int, shares: ?int, reach: ?int}
     */
    private function look(array $p): array
    {
        $none = ['status' => 'error', 'reactions' => null, 'comments' => null, 'shares' => null, 'reach' => null];
        $cred = $this->safe(fn () => $this->repo->credentials($p['account_id']));
        if ($cred === null || $cred['tenant_id'] !== $p['tenant_id'] || $cred['status'] !== 'active') {
            return $none;
        }
        $token = $this->secrets->open($cred['cipher'], $cred['version']);
        if ($token === null) {
            return $none;
        }
        $s = $this->graph->stats($p['remote_id'], $token, $p['media_kind']);
        if ($s['status'] === 'token') {
            $this->safe(fn () => $this->repo->markAccount($p['account_id'], 'invalid'));
        }

        return $s;
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
