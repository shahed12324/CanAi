<?php

declare(strict_types=1);

namespace App\Ads;

use App\Outreach\HttpJson;
use App\Social\GraphResult;

/**
 * Meta Marketing API — creates the four objects of an ad.  There is deliberately NO method that sets anything ACTIVE: every campaign, ad set and ad is sent with status=PAUSED
 * (the whole point of this class), and nothing here can edit or delete anything.  The token goes in an Authorization header.
 * A call whose answer we did not get (timeout, 5xx, no readable id) is reported as "unknown" — the caller must not retry it on its own.
 */
final class MetaAdsLaunchApi
{
    public function __construct(private readonly HttpJson $http, private readonly string $version = 'v24.0') {}

    /** @param  array<string, string>  $params */
    public function campaign(string $adAccountId, string $token, array $params): GraphResult
    {
        return $this->create("act_{$adAccountId}/campaigns", ['status' => 'PAUSED'] + $params, $token, $adAccountId);
    }

    /** @param  array<string, string>  $params */
    public function adSet(string $adAccountId, string $token, array $params): GraphResult
    {
        return $this->create("act_{$adAccountId}/adsets", ['status' => 'PAUSED'] + $params, $token, $adAccountId);
    }

    /** @param  array<string, string>  $params */
    public function creative(string $adAccountId, string $token, array $params): GraphResult
    {
        return $this->create("act_{$adAccountId}/adcreatives", $params, $token, $adAccountId);
    }

    /** @param  array<string, string>  $params */
    public function ad(string $adAccountId, string $token, array $params): GraphResult
    {
        return $this->create("act_{$adAccountId}/ads", ['status' => 'PAUSED'] + $params, $token, $adAccountId);
    }

    /**
     * Pauses an existing campaign — the ONLY change this class can make to something that already exists, and the only status it can send is PAUSED.  Pausing is idempotent, so a retry is always safe.
     * ok = Meta confirmed; token / rejected / unknown as for creation.
     */
    public function pauseCampaign(string $campaignId, string $token): GraphResult
    {
        if (preg_match('/\A[0-9]{5,30}\z/', $campaignId) !== 1) {
            return GraphResult::rejected('The campaign ID is not valid.');
        }
        try {
            $r = $this->http->request('POST', "https://graph.facebook.com/{$this->version}/{$campaignId}", ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'], ['status' => 'PAUSED']);
        } catch (\Throwable) {
            return GraphResult::unknown('Meta-reply was not received.');
        }
        $j = $r['json'];
        if ($r['status'] >= 200 && $r['status'] < 300 && ! isset($j['error']) && ($j['success'] ?? null) === true) {
            return GraphResult::ok($campaignId, '');
        }
        if (MetaAdsApi::isTokenError($j)) {
            return GraphResult::token(MetaAdsApi::errorText($j));
        }
        if ($r['status'] >= 500 || ! isset($j['error']) || in_array((int) ($j['error']['code'] ?? 0), [1, 2], true)) {
            return GraphResult::unknown('Meta-has a temporary problem.');
        }

        return GraphResult::rejected(MetaAdsApi::errorText($j));
    }

    /** @param  array<string, string>  $params */
    private function create(string $path, array $params, string $token, string $adAccountId): GraphResult
    {
        if (preg_match('/\A[0-9]{5,30}\z/', $adAccountId) !== 1) {
            return GraphResult::rejected('The ad account ID is not valid.');
        }
        try {
            $r = $this->http->request('POST', "https://graph.facebook.com/{$this->version}/{$path}", ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'], $params);
        } catch (\Throwable) {
            return GraphResult::unknown('Meta-reply was not received — it is not known whether it was created.');
        }
        $j = $r['json'];
        if ($r['status'] >= 200 && $r['status'] < 300 && ! isset($j['error'])) {
            $id = (string) ($j['id'] ?? '');

            return preg_match('/\A[0-9]{5,30}\z/', $id) === 1 ? GraphResult::ok($id, '') : GraphResult::unknown('Meta It responded but the ID could not be understood.');
        }
        if (MetaAdsApi::isTokenError($j)) {
            return GraphResult::token(MetaAdsApi::errorText($j));
        }
        if ($r['status'] >= 500 || ! isset($j['error']) || in_array((int) ($j['error']['code'] ?? 0), [1, 2], true)) {
            return GraphResult::unknown('Meta-has a temporary problem — it is not known whether it was created.');
        }
        $user = is_array($j['error']) && is_string($j['error']['error_user_msg'] ?? null) ? trim((string) preg_replace('/[\x00-\x1f\x7f]/u', ' ', $j['error']['error_user_msg'])) : '';

        return GraphResult::rejected(MetaAdsApi::errorText($j).($user !== '' ? ' — '.mb_substr($user, 0, 200) : ''));
    }
}
