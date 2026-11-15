<?php

declare(strict_types=1);

namespace App\GoogleAds;

use App\Social\TokenSealer;

/** Copies campaigns and the last days' results of each due Google Ads account (read only).  A failed look is recorded on the account; a revoked or rejected permission marks it invalid. */
final class GoogleAdsSync
{
    public const DAYS = 30;

    public function __construct(private readonly GoogleAdsRepo $repo, private readonly TokenSealer $secrets, private readonly GoogleAdsApi $api) {}

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

    /** @param  array{account_id: string, tenant_id: string, customer_id: string, login_id: ?string, cipher: string, version: int}  $a */
    private function one(array $a): bool
    {
        $fail = fn (string $why, bool $invalid = false) => $this->safe(fn () => $this->repo->fail($a['tenant_id'], $a['account_id'], $why, $invalid)) && false;
        $refresh = $this->secrets->open($a['cipher'], $a['version']);
        if ($refresh === null) {
            return $fail('The token could not be opened — connect the account again.', true);
        }
        $t = $this->api->refresh($refresh);
        if ($t['status'] === 'revoked') {
            return $fail('Google Permission was withdrawn — connect the account again.', true);
        }
        if ($t['status'] !== 'ok') {
            return $fail('Google-could not be reached — it will retry by itself shortly.');
        }
        $c = $this->api->campaigns($a['customer_id'], $a['login_id'], $t['access']);
        if ($c['status'] === 'token') {
            return $fail('Google did not accept the permission — connect the account again.', true);
        }
        if ($c['status'] !== 'ok') {
            return $fail('Google Ads Campaigns could not be fetched from — it will retry by itself shortly.');
        }
        $i = $this->api->insights($a['customer_id'], $a['login_id'], $t['access'], self::DAYS);
        if ($i['status'] === 'token') {
            return $fail('Google did not accept the permission — connect the account again.', true);
        }
        if ($i['status'] !== 'ok') {
            return $fail('Google Ads Results could not be fetched from — it will retry by itself shortly.');
        }

        return $this->safe(fn () => $this->repo->store($a['tenant_id'], $a['account_id'], $c['items'], $i['items'])) === 'ok';
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
