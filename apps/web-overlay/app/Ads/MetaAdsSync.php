<?php

declare(strict_types=1);

namespace App\Ads;

use App\Social\TokenSealer;

/** Copies campaigns and the last days' results of each due ad account from Meta (read only).  A failed look is recorded on the account; a rejected token marks it invalid. */
final class MetaAdsSync
{
    public const DAYS = 30;

    public function __construct(private readonly AdsRepo $repo, private readonly TokenSealer $secrets, private readonly MetaAdsApi $api) {}

    /** @return array{looked: int, saved: int} */
    public function run(int $limit, int $minutes = 60): array
    {
        $looked = 0;
        $saved = 0;
        foreach ($this->safe(fn () => $this->repo->syncDue($limit, $minutes)) ?? [] as $a) {
            $looked++;
            $saved += $this->one($a) ? 1 : 0;
        }

        return ['looked' => $looked, 'saved' => $saved];
    }

    /** @param  array{account_id: string, tenant_id: string, external_id: string, cipher: string, version: int}  $a */
    private function one(array $a): bool
    {
        $fail = fn (string $why, bool $invalid = false) => $this->safe(fn () => $this->repo->fail($a['tenant_id'], $a['account_id'], $why, $invalid)) && false;
        $token = $this->secrets->open($a['cipher'], $a['version']);
        if ($token === null) {
            return $fail('The token could not be opened — connect the account again.', true);
        }
        $c = $this->api->campaigns($a['external_id'], $token);
        if ($c['status'] === 'token') {
            return $fail('Meta The token was not accepted — connect the account again.', true);
        }
        if ($c['status'] !== 'ok') {
            return $fail('Meta Campaigns could not be fetched from — it will retry by itself shortly.');
        }
        $i = $this->api->insights($a['external_id'], $token, self::DAYS);
        if ($i['status'] === 'token') {
            return $fail('Meta The token was not accepted — connect the account again.', true);
        }
        if ($i['status'] !== 'ok') {
            return $fail('Meta Results could not be fetched from — it will retry by itself shortly.');
        }
        $campaigns = array_map(fn ($x) => $x + ['daily_budget' => null], $c['items']);

        return $this->safe(fn () => $this->repo->store($a['tenant_id'], $a['account_id'], $campaigns, $i['items'])) === 'ok';
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
