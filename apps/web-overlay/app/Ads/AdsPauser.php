<?php

declare(strict_types=1);

namespace App\Ads;

use App\Social\TokenSealer;

/** Worker: pauses the campaigns people asked to pause (approved pause requests).  Nothing else — it cannot activate or edit anything. */
final class AdsPauser
{
    public function __construct(private readonly AdsAdviceRepo $repo, private readonly AdsRepo $accounts, private readonly TokenSealer $secrets, private readonly MetaAdsLaunchApi $api) {}

    /** @return array{looked: int, paused: int} */
    public function run(int $limit): array
    {
        $looked = 0;
        $paused = 0;
        try {
            $due = $this->repo->claim($limit);
        } catch (\Throwable $e) {
            $this->note($e);
            $due = [];
        }
        foreach ($due as $q) {
            $looked++;
            $paused += $this->one($q) ? 1 : 0;
        }

        return ['looked' => $looked, 'paused' => $paused];
    }

    /** @param  array{req_id: string, tenant_id: string, account_id: string, account_ext: string, campaign: string, cipher: string, version: int}  $q */
    private function one(array $q): bool
    {
        $end = function (bool $ok, string $why) use ($q): bool {
            try {
                $this->repo->done($q['tenant_id'], $q['req_id'], $ok, $why);
            } catch (\Throwable $e) {
                $this->note($e);
            }

            return $ok;
        };
        $token = $this->secrets->open($q['cipher'], $q['version']);
        if ($token === null) {
            return $end(false, 'The token could not be opened — connect the account again.');
        }
        $res = $this->api->pauseCampaign($q['campaign'], $token);
        if ($res->status === 'ok') {
            return $end(true, '');
        }
        if ($res->status === 'token') {
            try {
                $this->accounts->fail($q['tenant_id'], $q['account_id'], 'Meta The token was not accepted — connect the account again (with ads_management permission).', true);
            } catch (\Throwable $e) {
                $this->note($e);
            }

            return $end(false, 'Meta The token was not accepted (ads_management permission is needed) — connect the account again.');
        }

        return $end(false, mb_substr($res->status === 'unknown' ? 'The result is unknown — check in Ads Manager, and request again if needed. ' : 'Meta Did not pause: ', 0, 80).($res->status === 'unknown' ? '' : $res->message));
    }

    private function note(\Throwable $e): void
    {
        if (function_exists('report')) {
            report($e);
        }
    }
}
