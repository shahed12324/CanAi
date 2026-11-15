<?php

declare(strict_types=1);

namespace App\Ads;

use App\Outreach\HttpJson;

/**
 * Meta Marketing API — READ ONLY (account check, campaign list, daily results).  Nothing here can create, change, pause or spend anything.
 * The access token travels in an Authorization header, never in a URL.  Numbers are passed on exactly as Meta sends them (the account's currency); "results" is a best-effort
 * count taken from the first matching action type below, so it can differ from the "Results" column in Ads Manager.
 */
final class MetaAdsApi
{
    /** In this order; the first one present in a row is that row's "results" (so purchases are not counted twice as omni_purchase + purchase). */
    public const RESULT_ACTIONS = ['omni_purchase', 'purchase', 'lead', 'onsite_conversion.lead_grouped', 'onsite_conversion.messaging_conversation_started_7d'];

    private const MAX_PAGES = 4;

    public function __construct(private readonly HttpJson $http, private readonly string $version = 'v24.0') {}

    private function url(string $path, array $query = []): string
    {
        return "https://graph.facebook.com/{$this->version}/{$path}".($query === [] ? '' : '?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986));
    }

    /** @return array{Authorization: string, Accept: string} */
    private static function headers(string $token): array
    {
        return ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'];
    }

    /**
     * Checks an ad account id with its token.
     *
     * @return array{ok: bool, id: string, name: string, currency: string, active: bool, token: bool, message: string}
     */
    public function account(string $adAccountId, string $token): array
    {
        $bad = fn (bool $tok, string $m) => ['ok' => false, 'id' => '', 'name' => '', 'currency' => '', 'active' => false, 'token' => $tok, 'message' => $m];
        if (preg_match('/\A[0-9]{5,30}\z/', $adAccountId) !== 1) {
            return $bad(false, 'The ad account ID is not valid.');
        }
        try {
            $r = $this->http->request('GET', $this->url("act_{$adAccountId}", ['fields' => 'account_id,name,currency,account_status']), self::headers($token));
        } catch (\Throwable) {
            return $bad(false, 'Meta-could not be reached.');
        }
        $j = $r['json'];
        $id = (string) ($j['account_id'] ?? '');
        $cur = (string) ($j['currency'] ?? '');
        if ($r['status'] !== 200 || isset($j['error']) || $id === '' || preg_match('/\A[A-Z]{3}\z/', $cur) !== 1) {
            return $bad(self::isTokenError($j), isset($j['error']) ? self::errorText($j) : 'Meta did not give this account’s information.');
        }
        $name = trim((string) preg_replace('/[\x00-\x1f\x7f]/u', ' ', (string) ($j['name'] ?? '')));

        return ['ok' => true, 'id' => $id, 'name' => $name === '' ? 'Meta Ads '.$id : mb_substr($name, 0, 120), 'currency' => $cur, 'active' => (int) ($j['account_status'] ?? 0) === 1, 'token' => false, 'message' => ''];
    }

    /**
     * @return array{status: string, items: list<array{id: string, name: string, objective: ?string, status: string}>} status: ok | token | error
     */
    public function campaigns(string $adAccountId, string $token): array
    {
        $rows = $this->pages("act_{$adAccountId}/campaigns", ['fields' => 'id,name,objective,effective_status', 'limit' => '100'], $token);
        if (is_string($rows)) {
            return ['status' => $rows, 'items' => []];
        }
        $items = [];
        foreach ($rows as $c) {
            if (! is_array($c) || ! is_string($c['id'] ?? null) || preg_match('/\A[0-9]{5,30}\z/', $c['id']) !== 1) {
                continue;
            }
            $items[] = ['id' => $c['id'], 'name' => mb_substr(trim((string) preg_replace('/[\x00-\x1f\x7f]/u', ' ', (string) ($c['name'] ?? ''))), 0, 200), 'objective' => is_string($c['objective'] ?? null) ? mb_substr($c['objective'], 0, 60) : null,
                'status' => mb_substr(is_string($c['effective_status'] ?? null) ? $c['effective_status'] : 'UNKNOWN', 0, 30)];
        }

        return ['status' => 'ok', 'items' => $items];
    }

    /**
     * Daily numbers per campaign for the last $days days (1–90).
     *
     * @return array{status: string, items: list<array{campaign: string, day: string, spend: string, impressions: string, clicks: string, results: string}>}
     */
    public function insights(string $adAccountId, string $token, int $days): array
    {
        $days = max(1, min(90, $days));
        $since = gmdate('Y-m-d', time() - ($days * 86400));
        $until = gmdate('Y-m-d');
        $rows = $this->pages("act_{$adAccountId}/insights", ['level' => 'campaign', 'time_increment' => '1', 'fields' => 'campaign_id,spend,impressions,clicks,actions', 'time_range' => json_encode(['since' => $since, 'until' => $until]), 'limit' => '500'], $token);
        if (is_string($rows)) {
            return ['status' => $rows, 'items' => []];
        }
        $items = [];
        foreach ($rows as $r) {
            if (! is_array($r) || ! is_string($r['campaign_id'] ?? null) || ! is_string($r['date_start'] ?? null)) {
                continue;
            }
            $num = fn ($v) => is_string($v) || is_int($v) || is_float($v) ? (string) $v : '0';
            $items[] = ['campaign' => $r['campaign_id'], 'day' => $r['date_start'], 'spend' => $num($r['spend'] ?? '0'), 'impressions' => $num($r['impressions'] ?? '0'), 'clicks' => $num($r['clicks'] ?? '0'), 'results' => self::results($r['actions'] ?? null)];
        }

        return ['status' => 'ok', 'items' => $items];
    }

    /** @param  mixed  $actions */
    public static function results($actions): string
    {
        if (! is_array($actions)) {
            return '0';
        }
        $by = [];
        foreach ($actions as $a) {
            if (is_array($a) && is_string($a['action_type'] ?? null) && is_string($a['value'] ?? null) && preg_match('/\A[0-9]{1,15}\z/', $a['value']) === 1) {
                $by[$a['action_type']] = ($by[$a['action_type']] ?? 0) + (int) $a['value'];
            }
        }
        foreach (self::RESULT_ACTIONS as $t) {
            if (isset($by[$t])) {
                return (string) $by[$t];
            }
        }

        return '0';
    }

    /**
     * Follows Meta's "after" cursor (never a URL taken from the response).
     *
     * @param  array<string, string>  $query
     * @return list<mixed>|string a list of rows, or 'token' | 'error'
     */
    private function pages(string $path, array $query, string $token): array|string
    {
        $all = [];
        $after = null;
        for ($i = 0; $i < self::MAX_PAGES; $i++) {
            $q = $query + ($after === null ? [] : ['after' => $after]);
            try {
                $r = $this->http->request('GET', $this->url($path, $q), self::headers($token));
            } catch (\Throwable) {
                return 'error';
            }
            $j = $r['json'];
            if ($r['status'] !== 200 || isset($j['error']) || ! is_array($j['data'] ?? null)) {
                return self::isTokenError($j) ? 'token' : 'error';
            }
            array_push($all, ...array_values($j['data']));
            $next = $j['paging']['cursors']['after'] ?? null;
            if (! is_string($next) || $next === '' || ! isset($j['paging']['next']) || preg_match('/\A[A-Za-z0-9_=\-]{1,300}\z/', $next) !== 1) {
                return $all;
            }
            $after = $next;
        }

        return $all;
    }

    /** @param  array<string, mixed>  $j */
    public static function isTokenError(array $j): bool
    {
        $e = $j['error'] ?? null;

        return is_array($e) && (in_array((int) ($e['code'] ?? 0), [102, 190], true) || (($e['type'] ?? '') === 'OAuthException' && in_array((int) ($e['code'] ?? 0), [10, 200], true)));
    }

    /** @param  array<string, mixed>  $j */
    public static function errorText(array $j): string
    {
        $e = $j['error'] ?? null;
        $m = is_array($e) ? trim((string) preg_replace('/[\x00-\x1f\x7f]/u', ' ', (string) ($e['message'] ?? ''))) : '';
        $code = is_array($e) ? (int) ($e['code'] ?? 0) : 0;

        return 'Meta: '.($m === '' ? 'Unknown error' : mb_substr($m, 0, 200)).($code > 0 ? " (Code {$code})" : '');
    }
}
