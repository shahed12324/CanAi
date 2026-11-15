<?php

declare(strict_types=1);

namespace App\GoogleAds;

use App\Social\TokenSealer;

/** Worker: pauses the Google campaigns people asked to pause (approved pause requests).  Nothing else — it cannot enable or edit anything. */
final class GadsPauser
{
    public function __construct(private readonly GadsAdviceRepo $repo, private readonly GoogleAdsRepo $accounts, private readonly TokenSealer $secrets, private readonly GoogleAdsApi $auth, private readonly GoogleAdsLaunchApi $api) {}

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

    /** @param  array{req_id: string, tenant_id: string, account_id: string, customer_id: string, login_id: ?string, campaign: string, cipher: string, version: int}  $q */
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
        $invalid = function (string $why) use ($q): void {
            try {
                $this->accounts->fail($q['tenant_id'], $q['account_id'], $why, true);
            } catch (\Throwable $e) {
                $this->note($e);
            }
        };
        $refresh = $this->secrets->open($q['cipher'], $q['version']);
        if ($refresh === null) {
            return $end(false, 'The token could not be opened — connect the account again.');
        }
        $t = $this->auth->refresh($refresh);
        if ($t['status'] === 'revoked') {
            $invalid('Google Permission was withdrawn — connect the account again.');

            return $end(false, 'Google Permission was withdrawn — connect the account again.');
        }
        if ($t['status'] !== 'ok') {
            return $end(false, 'Google-could not be reached — request again.');
        }
        $res = $this->api->pauseCampaign($q['customer_id'], $q['login_id'], $t['access'], $q['campaign']);
        if ($res->status === 'ok') {
            return $end(true, '');
        }
        if ($res->status === 'token') {
            $invalid('Google did not accept the permission — connect the account again.');

            return $end(false, 'Google did not accept the permission — connect the account again.');
        }

        return $end(false, $res->status === 'unknown' ? 'The result is unknown — check in Google Ads, and request again if needed.' : 'Google Did not pause: '.$res->message);
    }

    private function note(\Throwable $e): void
    {
        if (function_exists('report')) {
            report($e);
        }
    }
}
