<?php

declare(strict_types=1);

namespace App\GoogleAds;

use App\Outreach\HttpJson;
use App\Social\GraphResult;

/**
 * Google Ads API — creates the objects of a Search campaign, and can pause a campaign.  Create operations plus ONE update (status → PAUSED); no remove, and nothing here can enable, edit or delete anything.
 * New campaigns are always sent with status PAUSED (set here, not by the caller).  Tokens go in headers.
 * A call whose answer we did not get (timeout, 5xx, no readable id) is reported as "unknown" — the caller must not retry it on its own.
 */
final class GoogleAdsLaunchApi
{
    public function __construct(private readonly HttpJson $http, private readonly ?string $developerToken, private readonly string $version = 'v24') {}

    /** @param  array<string, mixed>  $fields  name, amountMicros, … */
    public function budget(string $customer, ?string $login, string $access, array $fields): GraphResult
    {
        return $this->single('campaignBudgets', $customer, $login, $access, $fields);
    }

    /** @param  array<string, mixed>  $fields  Always PAUSED. */
    public function campaign(string $customer, ?string $login, string $access, string $budgetId, array $fields): GraphResult
    {
        return $this->single('campaigns', $customer, $login, $access, ['status' => 'PAUSED', 'campaignBudget' => "customers/{$customer}/campaignBudgets/{$budgetId}"] + $fields);
    }

    /** @param  array<string, mixed>  $fields */
    public function adGroup(string $customer, ?string $login, string $access, string $campaignId, array $fields): GraphResult
    {
        return $this->single('adGroups', $customer, $login, $access, ['status' => 'ENABLED', 'campaign' => "customers/{$customer}/campaigns/{$campaignId}"] + $fields);
    }

    /** @param  array<string, mixed>  $ad  finalUrls + responsiveSearchAd */
    public function ad(string $customer, ?string $login, string $access, string $adGroupId, array $ad): GraphResult
    {
        return $this->single('adGroupAds', $customer, $login, $access, ['status' => 'ENABLED', 'adGroup' => "customers/{$customer}/adGroups/{$adGroupId}", 'ad' => $ad]);
    }

    /** @param  list<array{text: string, matchType: string}>  $keywords one atomic request: all or none */
    public function keywords(string $customer, ?string $login, string $access, string $adGroupId, array $keywords): GraphResult
    {
        $group = "customers/{$customer}/adGroups/{$adGroupId}";
        $ops = array_map(fn ($k) => ['create' => ['adGroup' => $group, 'status' => 'ENABLED', 'keyword' => ['text' => $k['text'], 'matchType' => $k['matchType']]]], $keywords);

        return $this->mutate('adGroupCriteria', $customer, $login, $access, $ops, true);
    }

    /**
     * Location, language and negative keywords of the campaign — one atomic request.
     *
     * @param  list<array<string, mixed>>  $criteria
     */
    public function targeting(string $customer, ?string $login, string $access, string $campaignId, array $criteria): GraphResult
    {
        $camp = "customers/{$customer}/campaigns/{$campaignId}";
        $ops = array_map(fn ($c) => ['create' => ['campaign' => $camp] + $c], $criteria);

        return $this->mutate('campaignCriteria', $customer, $login, $access, $ops, true);
    }

    /**
     * Pauses an existing campaign — the ONLY change this class can make to something that already exists, and the only value it can write is status PAUSED (updateMask is fixed to "status").
     * Pausing is idempotent, so a retry is always safe.
     */
    public function pauseCampaign(string $customer, ?string $login, string $access, string $campaignId): GraphResult
    {
        if (preg_match('/\A[0-9]{1,20}\z/', $campaignId) !== 1) {
            return GraphResult::rejected('The campaign ID is not valid.');
        }

        return $this->mutate('campaigns', $customer, $login, $access, [['updateMask' => 'status', 'update' => ['resourceName' => "customers/{$customer}/campaigns/{$campaignId}", 'status' => 'PAUSED']]], false);
    }

    /** @param  array<string, mixed>  $fields */
    private function single(string $service, string $customer, ?string $login, string $access, array $fields): GraphResult
    {
        return $this->mutate($service, $customer, $login, $access, [['create' => $fields]], false);
    }

    /** @param  list<array<string, mixed>>  $ops  built only by the methods above: creates, and the one fixed PAUSED update */
    private function mutate(string $service, string $customer, ?string $login, string $access, array $ops, bool $many): GraphResult
    {
        if (preg_match('/\A[0-9]{10}\z/', $customer) !== 1 || ($login !== null && preg_match('/\A[0-9]{10}\z/', $login) !== 1)) {
            return GraphResult::rejected('Google Ads The account ID is not valid.');
        }
        $headers = ['Authorization' => 'Bearer '.$access, 'developer-token' => (string) $this->developerToken, 'Content-Type' => 'application/json', 'Accept' => 'application/json'];
        if ($login !== null) {
            $headers['login-customer-id'] = $login;
        }
        try {
            $r = $this->http->request('POST', "https://googleads.googleapis.com/{$this->version}/customers/{$customer}/{$service}:mutate", $headers, null, json_encode(['operations' => $ops], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } catch (\Throwable) {
            return GraphResult::unknown('Google-reply was not received — it is not known whether it was created.');
        }
        $j = $r['json'];
        if ($r['status'] >= 200 && $r['status'] < 300 && ! isset($j['error'])) {
            $res = is_array($j['results'] ?? null) ? array_values($j['results']) : [];
            if ($many) {
                return count($res) === count($ops) ? GraphResult::ok('done', '') : GraphResult::unknown('Google It responded but the result did not match.');
            }
            $name = is_array($res[0] ?? null) ? (string) ($res[0]['resourceName'] ?? '') : '';
            if (count($res) === 1 && preg_match('#\Acustomers/[0-9]{10}/[A-Za-z]+/(?:[0-9]{5,30}~)?([0-9]{5,30})\z#', $name, $m) === 1) {
                return GraphResult::ok($m[1], '');
            }

            return GraphResult::unknown('Google It responded but the ID could not be understood.');
        }
        $e = is_array($j['error'] ?? null) ? $j['error'] : [];
        if ($r['status'] === 401 || ($e['status'] ?? '') === 'UNAUTHENTICATED') {
            return GraphResult::token('Google Did not accept the permission.');
        }
        if ($r['status'] >= 500 || $e === []) {
            return GraphResult::unknown('Google-has a temporary problem — it is not known whether it was created.');
        }

        return GraphResult::rejected(self::reason($r['status'], $e));
    }

    /** @param  array<string, mixed>  $e */
    private static function reason(int $status, array $e): string
    {
        if ($status === 403 || ($e['status'] ?? '') === 'PERMISSION_DENIED') {
            return 'Google Ads: No permission to create on this account or the Developer Token level is not enough (at Test level only a test account works).';
        }
        $msg = '';
        foreach (is_array($e['details'] ?? null) ? $e['details'] : [] as $d) {
            foreach (is_array($d['errors'] ?? null) ? $d['errors'] : [] as $x) {
                if (is_array($x) && is_string($x['message'] ?? null) && $x['message'] !== '') {
                    $msg = $x['message'];
                    break 2;
                }
            }
        }
        if ($msg === '' && is_string($e['message'] ?? null)) {
            $msg = $e['message'];
        }
        $msg = trim((string) preg_replace('/[\x00-\x1f\x7f]/u', ' ', $msg));

        return $msg === '' ? 'Google Ads did not accept the request.' : 'Google Ads: '.mb_substr($msg, 0, 250);
    }
}
