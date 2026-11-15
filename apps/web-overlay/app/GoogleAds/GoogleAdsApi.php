<?php

declare(strict_types=1);

namespace App\GoogleAds;

use App\Outreach\HttpJson;

/**
 * Google OAuth + Google Ads API (REST) — READ ONLY in this phase: it can describe a customer, list campaigns and fetch daily results; it has no method that creates or changes anything.
 * The platform's developer token and OAuth client come from configuration; each workspace's refresh token is sealed by the caller.  Tokens travel only in headers / form bodies.
 */
final class GoogleAdsApi
{
    public const SCOPE = 'https://www.googleapis.com/auth/adwords';

    private const MAX_PAGES = 20;

    public function __construct(private readonly HttpJson $http, private readonly ?string $clientId, private readonly ?string $secret, private readonly ?string $developerToken, private readonly string $version = 'v24') {}

    public function configured(): bool
    {
        foreach ([$this->clientId, $this->secret, $this->developerToken] as $v) {
            if ($v === null || $v === '') {
                return false;
            }
        }

        return true;
    }

    public function authUrl(string $redirectUri, string $state, string $challenge): string
    {
        return 'https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query([
            'client_id' => $this->clientId, 'redirect_uri' => $redirectUri, 'response_type' => 'code', 'scope' => self::SCOPE,
            'access_type' => 'offline', 'prompt' => 'consent', 'state' => $state, 'code_challenge' => $challenge, 'code_challenge_method' => 'S256',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /** @return array{status: string, refresh: string, access: string} status: ok | failed | no_refresh | scope */
    public function exchange(string $code, string $verifier, string $redirectUri): array
    {
        $bad = fn (string $s) => ['status' => $s, 'refresh' => '', 'access' => ''];
        try {
            $r = $this->http->request('POST', 'https://oauth2.googleapis.com/token', [], ['code' => $code, 'client_id' => (string) $this->clientId, 'client_secret' => (string) $this->secret, 'redirect_uri' => $redirectUri, 'grant_type' => 'authorization_code', 'code_verifier' => $verifier]);
        } catch (\Throwable) {
            return $bad('failed');
        }
        $j = $r['json'];
        if ($r['status'] !== 200 || ! is_string($j['access_token'] ?? null)) {
            return $bad('failed');
        }
        if (! is_string($j['refresh_token'] ?? null) || $j['refresh_token'] === '') {
            return $bad('no_refresh');
        }
        if (! str_contains((string) ($j['scope'] ?? ''), 'auth/adwords')) {
            return $bad('scope');
        }

        return ['status' => 'ok', 'refresh' => $j['refresh_token'], 'access' => $j['access_token']];
    }

    /** @return array{status: string, access: string} status: ok | revoked | error */
    public function refresh(string $refreshToken): array
    {
        try {
            $r = $this->http->request('POST', 'https://oauth2.googleapis.com/token', [], ['client_id' => (string) $this->clientId, 'client_secret' => (string) $this->secret, 'refresh_token' => $refreshToken, 'grant_type' => 'refresh_token']);
        } catch (\Throwable) {
            return ['status' => 'error', 'access' => ''];
        }
        if (($r['json']['error'] ?? null) === 'invalid_grant') {
            return ['status' => 'revoked', 'access' => ''];
        }
        if ($r['status'] !== 200 || ! is_string($r['json']['access_token'] ?? null) || $r['json']['access_token'] === '') {
            return ['status' => 'error', 'access' => ''];
        }

        return ['status' => 'ok', 'access' => $r['json']['access_token']];
    }

    /** @return array{ok: bool, name: string, currency: string, active: bool, token: bool, message: string} */
    public function customer(string $customerId, ?string $loginId, string $access): array
    {
        $bad = fn (bool $tok, string $m) => ['ok' => false, 'name' => '', 'currency' => '', 'active' => false, 'token' => $tok, 'message' => $m];
        $rows = $this->search($customerId, $loginId, $access, 'SELECT customer.id, customer.descriptive_name, customer.currency_code, customer.status FROM customer LIMIT 1');
        if ($rows === 'token') {
            return $bad(true, 'Google Did not accept the permission.');
        }
        if (is_string($rows)) {
            return $bad(false, $rows === 'error' ? 'Google Ads did not give this account’s information (check Customer ID, manager ID and Developer Token).' : $rows);
        }
        $c = $rows[0]['customer'] ?? null;
        $cur = is_array($c) ? (string) ($c['currencyCode'] ?? '') : '';
        if (! is_array($c) || preg_match('/\A[A-Z]{3}\z/', $cur) !== 1 || (string) ($c['id'] ?? '') !== $customerId) {
            return $bad(false, 'Google Ads did not give this account’s information.');
        }
        $name = trim((string) preg_replace('/[\x00-\x1f\x7f]/u', ' ', (string) ($c['descriptiveName'] ?? '')));

        return ['ok' => true, 'name' => $name === '' ? 'Google Ads '.$customerId : mb_substr($name, 0, 120), 'currency' => $cur, 'active' => ($c['status'] ?? '') === 'ENABLED', 'token' => false, 'message' => ''];
    }

    /** @return array{status: string, items: list<array{id: string, name: string, channel: ?string, status: string, daily_budget: ?string}>} status: ok | token | error */
    public function campaigns(string $customerId, ?string $loginId, string $access): array
    {
        $rows = $this->search($customerId, $loginId, $access, "SELECT campaign.id, campaign.name, campaign.status, campaign.advertising_channel_type, campaign_budget.amount_micros FROM campaign WHERE campaign.status != 'REMOVED'");
        if (is_string($rows)) {
            return ['status' => $rows === 'token' ? 'token' : 'error', 'items' => []];
        }
        $items = [];
        foreach ($rows as $r) {
            $c = $r['campaign'] ?? null;
            if (! is_array($c) || preg_match('/\A[0-9]{1,20}\z/', (string) ($c['id'] ?? '')) !== 1) {
                continue;
            }
            $items[] = ['id' => (string) $c['id'], 'name' => mb_substr(trim((string) preg_replace('/[\x00-\x1f\x7f]/u', ' ', (string) ($c['name'] ?? ''))), 0, 200),
                'channel' => is_string($c['advertisingChannelType'] ?? null) ? mb_substr($c['advertisingChannelType'], 0, 40) : null, 'status' => mb_substr(is_string($c['status'] ?? null) ? $c['status'] : 'UNKNOWN', 0, 30),
                'daily_budget' => self::money($r['campaignBudget']['amountMicros'] ?? null)];
        }

        return ['status' => 'ok', 'items' => $items];
    }

    /** @return array{status: string, items: list<array{campaign: string, day: string, cost: string, impressions: string, clicks: string, conversions: string}>} */
    public function insights(string $customerId, ?string $loginId, string $access, int $days): array
    {
        $days = max(1, min(90, $days));
        $since = gmdate('Y-m-d', time() - ($days * 86400));
        $until = gmdate('Y-m-d');
        $rows = $this->search($customerId, $loginId, $access, "SELECT campaign.id, segments.date, metrics.cost_micros, metrics.impressions, metrics.clicks, metrics.conversions FROM campaign WHERE segments.date BETWEEN '{$since}' AND '{$until}'");
        if (is_string($rows)) {
            return ['status' => $rows === 'token' ? 'token' : 'error', 'items' => []];
        }
        $items = [];
        foreach ($rows as $r) {
            $id = (string) ($r['campaign']['id'] ?? '');
            $day = (string) ($r['segments']['date'] ?? '');
            $m = $r['metrics'] ?? [];
            if (preg_match('/\A[0-9]{1,20}\z/', $id) !== 1 || preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/', $day) !== 1 || ! is_array($m)) {
                continue;
            }
            $num = fn ($v) => is_string($v) || is_int($v) || is_float($v) ? (string) $v : '0';
            $items[] = ['campaign' => $id, 'day' => $day, 'cost' => self::money($m['costMicros'] ?? '0') ?? '0', 'impressions' => $num($m['impressions'] ?? '0'), 'clicks' => $num($m['clicks'] ?? '0'),
                'conversions' => is_numeric($m['conversions'] ?? null) ? number_format((float) $m['conversions'], 2, '.', '') : '0'];
        }

        return ['status' => 'ok', 'items' => $items];
    }

    /** Micros (1/1,000,000 of the currency unit) → "12.34" (rounded to 2 decimals), or null when not a plain non-negative integer. */
    public static function money(mixed $micros): ?string
    {
        if ((! is_string($micros) && ! is_int($micros)) || preg_match('/\A[0-9]{1,15}\z/', (string) $micros) !== 1) {
            return null;
        }
        $cents = intdiv((int) $micros + 5000, 10000);

        return intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    /**
     * One GAQL query, following Google's page token (never a URL from the response).
     *
     * @return list<array<string, mixed>>|string a list of result rows, or 'token' | 'error' | a readable message (permission problems)
     */
    private function search(string $customerId, ?string $loginId, string $access, string $gaql): array|string
    {
        if (preg_match('/\A[0-9]{10}\z/', $customerId) !== 1 || ($loginId !== null && preg_match('/\A[0-9]{10}\z/', $loginId) !== 1)) {
            return 'error';
        }
        $headers = ['Authorization' => 'Bearer '.$access, 'developer-token' => (string) $this->developerToken, 'Content-Type' => 'application/json', 'Accept' => 'application/json'];
        if ($loginId !== null) {
            $headers['login-customer-id'] = $loginId;
        }
        $all = [];
        $page = null;
        for ($i = 0; $i < self::MAX_PAGES; $i++) {
            try {
                $r = $this->http->request('POST', "https://googleads.googleapis.com/{$this->version}/customers/{$customerId}/googleAds:search", $headers, null, json_encode(['query' => $gaql] + ($page === null ? [] : ['pageToken' => $page]), JSON_THROW_ON_ERROR));
            } catch (\Throwable) {
                return 'error';
            }
            $j = $r['json'];
            if ($r['status'] !== 200 || isset($j['error'])) {
                return self::problem($r['status'], $j);
            }
            array_push($all, ...array_values(array_filter(is_array($j['results'] ?? null) ? $j['results'] : [], 'is_array')));
            $next = $j['nextPageToken'] ?? null;
            if (! is_string($next) || $next === '' || preg_match('/\A[A-Za-z0-9_=\-+\/.]{1,2000}\z/', $next) !== 1) {
                return $all;
            }
            $page = $next;
        }

        return $all;
    }

    /** @param  array<string, mixed>  $j */
    private static function problem(int $status, array $j): string
    {
        $e = is_array($j['error'] ?? null) ? $j['error'] : [];
        if ($status === 401 || ($e['status'] ?? '') === 'UNAUTHENTICATED') {
            return 'token';
        }
        if ($status === 403 || ($e['status'] ?? '') === 'PERMISSION_DENIED') {
            return 'Google Ads: No permission on this account or the Developer Token is not approved — check the Customer ID, manager (MCC) ID and the Developer Token level.';
        }

        return 'error';
    }
}
