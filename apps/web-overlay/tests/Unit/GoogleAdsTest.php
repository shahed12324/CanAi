<?php

use App\GoogleAds\GoogleAdsAccounts;
use App\GoogleAds\GoogleAdsApi;
use App\GoogleAds\GoogleAdsSync;
use Tests\Support\FakeGoogleAdsRepo;
use Tests\Support\FakeHttpJson;
use Tests\Support\FakeSealer;

const GAD_REFRESH = '1//0g-refresh_token_value-0123456789abcdef';
const GAD_ACCESS = 'ya29.access_token_value_0123456789';
const GAD_CUST = '1234567890';
const GAD_DEV = 'DEVTOKEN-abc123';

function gadapi(): array
{
    $h = new FakeHttpJson;

    return [new GoogleAdsApi($h, 'client-id', 'client-secret', GAD_DEV, 'v24'), $h];
}

function gadcustomer(array $o = []): array
{
    return ['status' => 200, 'json' => ['results' => [['customer' => $o + ['id' => GAD_CUST, 'descriptiveName' => "আমার\nগুগল", 'currencyCode' => 'BDT', 'status' => 'ENABLED']]]]];
}

it('is configured only with client, secret and developer token', function () {
    $h = new FakeHttpJson;
    expect((new GoogleAdsApi($h, 'a', 'b', 'c'))->configured())->toBeTrue()->and((new GoogleAdsApi($h, 'a', 'b', null))->configured())->toBeFalse()->and((new GoogleAdsApi($h, '', 'b', 'c'))->configured())->toBeFalse()->and((new GoogleAdsApi($h, 'a', null, 'c'))->configured())->toBeFalse();
});

it('builds a consent URL for read access only, with PKCE and offline access', function () {
    [$api] = gadapi();
    $u = $api->authUrl('https://x.test/cb', 'STATE', 'CHAL');
    parse_str((string) parse_url($u, PHP_URL_QUERY), $q);
    expect($q['scope'])->toBe('https://www.googleapis.com/auth/adwords')->and($q['access_type'])->toBe('offline')->and($q['prompt'])->toBe('consent')->and($q['code_challenge_method'])->toBe('S256')->and($q['state'])->toBe('STATE')->and(str_contains($u, 'client-secret'))->toBeFalse();
});

it('exchanges a code: needs a refresh token and the Google Ads scope', function () {
    [$api, $h] = gadapi();
    $h->queue[] = ['status' => 200, 'json' => ['access_token' => GAD_ACCESS, 'refresh_token' => GAD_REFRESH, 'scope' => 'https://www.googleapis.com/auth/adwords']];
    expect($api->exchange('code', 'verifier', 'https://x.test/cb'))->toBe(['status' => 'ok', 'refresh' => GAD_REFRESH, 'access' => GAD_ACCESS]);
    expect($h->calls[0]['form']['code_verifier'])->toBe('verifier')->and($h->calls[0]['form']['grant_type'])->toBe('authorization_code');
    foreach ([[['status' => 200, 'json' => ['access_token' => 'a', 'scope' => 'https://www.googleapis.com/auth/adwords']], 'no_refresh'], [['status' => 200, 'json' => ['access_token' => 'a', 'refresh_token' => 'r', 'scope' => 'openid']], 'scope'], [['status' => 400, 'json' => ['error' => 'invalid_grant']], 'failed']] as [$resp, $want]) {
        $h->queue[] = $resp;
        expect($api->exchange('code', 'v', 'u')['status'])->toBe($want);
    }
});

it('refreshes an access token and tells a revoked grant apart', function () {
    [$api, $h] = gadapi();
    $h->queue[] = ['status' => 200, 'json' => ['access_token' => GAD_ACCESS]];
    expect($api->refresh(GAD_REFRESH))->toBe(['status' => 'ok', 'access' => GAD_ACCESS]);
    $h->queue[] = ['status' => 400, 'json' => ['error' => 'invalid_grant']];
    expect($api->refresh(GAD_REFRESH)['status'])->toBe('revoked');
    $h->queue[] = ['status' => 500, 'json' => []];
    expect($api->refresh(GAD_REFRESH)['status'])->toBe('error');
});

it('describes a customer with tokens in headers only', function () {
    [$api, $h] = gadapi();
    $h->queue[] = gadcustomer();
    $r = $api->customer(GAD_CUST, '9876543210', GAD_ACCESS);
    expect($r)->toBe(['ok' => true, 'name' => 'আমার গুগল', 'currency' => 'BDT', 'active' => true, 'token' => false, 'message' => '']);
    $c = $h->calls[0];
    expect($c['method'])->toBe('POST')->and($c['url'])->toBe('https://googleads.googleapis.com/v24/customers/'.GAD_CUST.'/googleAds:search')->and($c['headers']['Authorization'])->toBe('Bearer '.GAD_ACCESS)->and($c['headers']['developer-token'])->toBe(GAD_DEV)->and($c['headers']['login-customer-id'])->toBe('9876543210');
    expect(str_contains($c['url'], 'ya29'))->toBeFalse()->and(str_contains((string) $c['body'], 'ya29'))->toBeFalse()->and(json_decode((string) $c['body'], true)['query'])->toContain('FROM customer');
    $h->queue[] = gadcustomer();
    $api->customer(GAD_CUST, null, GAD_ACCESS);
    expect(isset($h->calls[1]['headers']['login-customer-id']))->toBeFalse();
});

it('refuses a customer Google did not describe, a disabled one is reported inactive', function () {
    [$api, $h] = gadapi();
    foreach ([['status' => 200, 'json' => ['results' => []]], gadcustomer(['id' => '1111111111']), gadcustomer(['currencyCode' => 'bdt']), ['status' => 500, 'json' => []]] as $resp) {
        $h->queue[] = $resp;
        $r = $api->customer(GAD_CUST, null, GAD_ACCESS);
        expect($r['ok'])->toBeFalse()->and($r['token'])->toBeFalse();
    }
    $h->queue[] = gadcustomer(['status' => 'SUSPENDED']);
    $r = $api->customer(GAD_CUST, null, GAD_ACCESS);
    expect($r['ok'])->toBeTrue()->and($r['active'])->toBeFalse();
    $h->queue[] = ['status' => 401, 'json' => ['error' => ['status' => 'UNAUTHENTICATED']]];
    expect($api->customer(GAD_CUST, null, GAD_ACCESS)['token'])->toBeTrue();
    $h->queue[] = ['status' => 403, 'json' => ['error' => ['status' => 'PERMISSION_DENIED']]];
    $r = $api->customer(GAD_CUST, null, GAD_ACCESS);
    expect($r['ok'])->toBeFalse()->and($r['token'])->toBeFalse()->and(str_contains($r['message'], 'Developer Token'))->toBeTrue();
    $n = count($h->calls);
    expect($api->customer('12ab', null, GAD_ACCESS)['ok'])->toBeFalse()->and($api->customer(GAD_CUST, 'x', GAD_ACCESS)['ok'])->toBeFalse()->and(count($h->calls))->toBe($n);
});

it('converts micros to two decimals without floating point', function () {
    expect(GoogleAdsApi::money('12340000'))->toBe('12.34')->and(GoogleAdsApi::money('1000000'))->toBe('1.00')->and(GoogleAdsApi::money('5000'))->toBe('0.01')->and(GoogleAdsApi::money('4999'))->toBe('0.00')->and(GoogleAdsApi::money('0'))->toBe('0.00')
        ->and(GoogleAdsApi::money(2500000))->toBe('2.50')->and(GoogleAdsApi::money('-5'))->toBeNull()->and(GoogleAdsApi::money('1.5'))->toBeNull()->and(GoogleAdsApi::money(null))->toBeNull()->and(GoogleAdsApi::money('9999999999999999'))->toBeNull();
});

it('reads campaigns and daily results, skipping malformed rows', function () {
    [$api, $h] = gadapi();
    $h->queue[] = ['status' => 200, 'json' => ['results' => [
        ['campaign' => ['id' => '111', 'name' => "ঈদ\nঅফার", 'status' => 'ENABLED', 'advertisingChannelType' => 'SEARCH'], 'campaignBudget' => ['amountMicros' => '500000000']],
        ['campaign' => ['id' => 'x', 'name' => 'bad', 'status' => 'ENABLED']],
        ['campaign' => ['id' => '222', 'name' => 'দুই', 'status' => 'PAUSED'], 'campaignBudget' => ['amountMicros' => 'abc']]]]];
    $c = $api->campaigns(GAD_CUST, null, GAD_ACCESS);
    expect($c['status'])->toBe('ok')->and($c['items'])->toBe([['id' => '111', 'name' => 'ঈদ অফার', 'channel' => 'SEARCH', 'status' => 'ENABLED', 'daily_budget' => '500.00'], ['id' => '222', 'name' => 'দুই', 'channel' => null, 'status' => 'PAUSED', 'daily_budget' => null]]);
    expect(json_decode((string) $h->calls[0]['body'], true)['query'])->toContain("status != 'REMOVED'");
    $h->queue[] = ['status' => 200, 'json' => ['results' => [
        ['campaign' => ['id' => '111'], 'segments' => ['date' => '2026-11-01'], 'metrics' => ['costMicros' => '12340000', 'impressions' => '1000', 'clicks' => '20', 'conversions' => 3.5]],
        ['campaign' => ['id' => '111'], 'segments' => ['date' => 'bad'], 'metrics' => []],
        ['campaign' => ['id' => '111'], 'segments' => ['date' => '2026-11-02'], 'metrics' => ['costMicros' => '0']]]]];
    $i = $api->insights(GAD_CUST, null, GAD_ACCESS, 30);
    expect($i['items'])->toBe([['campaign' => '111', 'day' => '2026-11-01', 'cost' => '12.34', 'impressions' => '1000', 'clicks' => '20', 'conversions' => '3.50'], ['campaign' => '111', 'day' => '2026-11-02', 'cost' => '0.00', 'impressions' => '0', 'clicks' => '0', 'conversions' => '0']]);
    expect(json_decode((string) $h->calls[1]['body'], true)['query'])->toContain('BETWEEN');
});

it('follows the page token and stops at a safe limit', function () {
    [$api, $h] = gadapi();
    $h->queue[] = ['status' => 200, 'json' => ['results' => [['campaign' => ['id' => '1', 'name' => 'a', 'status' => 'ENABLED']]], 'nextPageToken' => 'TOK_1=']];
    $h->queue[] = ['status' => 200, 'json' => ['results' => [['campaign' => ['id' => '2', 'name' => 'b', 'status' => 'ENABLED']]]]];
    expect(count($api->campaigns(GAD_CUST, null, GAD_ACCESS)['items']))->toBe(2)->and(json_decode((string) $h->calls[1]['body'], true)['pageToken'])->toBe('TOK_1=');
    $h2 = new FakeHttpJson;
    $api2 = new GoogleAdsApi($h2, 'a', 'b', 'c');
    for ($i = 0; $i < 40; $i++) {
        $h2->queue[] = ['status' => 200, 'json' => ['results' => [], 'nextPageToken' => 'T'.$i]];
    }
    $api2->campaigns(GAD_CUST, null, GAD_ACCESS);
    expect(count($h2->calls))->toBe(20);
    $h->queue[] = ['status' => 200, 'json' => ['results' => [], 'nextPageToken' => "bad token\n"]];
    $n = count($h->calls);
    $api->campaigns(GAD_CUST, null, GAD_ACCESS);
    expect(count($h->calls))->toBe($n + 1);
});

it('reports errors as token or error', function () {
    [$api, $h] = gadapi();
    $h->queue[] = ['status' => 401, 'json' => ['error' => ['status' => 'UNAUTHENTICATED']]];
    expect($api->campaigns(GAD_CUST, null, GAD_ACCESS)['status'])->toBe('token');
    $h->queue[] = ['status' => 403, 'json' => ['error' => ['status' => 'PERMISSION_DENIED']]];
    expect($api->insights(GAD_CUST, null, GAD_ACCESS, 7)['status'])->toBe('error');
    $h->queue[] = ['status' => 500, 'json' => []];
    expect($api->campaigns(GAD_CUST, null, GAD_ACCESS)['status'])->toBe('error');
    $h->queue[] = ['status' => 401, 'json' => []];
    expect($api->campaigns(GAD_CUST, null, GAD_ACCESS)['status'])->toBe('token');
    $h->queue[] = ['status' => 403, 'json' => ['error' => ['status' => 'OTHER']]];
    expect($api->customer(GAD_CUST, null, GAD_ACCESS)['message'])->toContain('Allowed নয়');
});

function gadconn(): array
{
    $h = new FakeHttpJson;
    $repo = new FakeGoogleAdsRepo;

    return [new GoogleAdsAccounts($repo, new FakeSealer, new GoogleAdsApi($h, 'a', 'b', 'c', 'v24')), $h, $repo];
}

it('cleans customer ids', function () {
    expect(GoogleAdsAccounts::cleanId('123-456-7890'))->toBe('1234567890')->and(GoogleAdsAccounts::cleanId(' 123 456 7890 '))->toBe('1234567890')->and(GoogleAdsAccounts::cleanId('12345'))->toBeNull()->and(GoogleAdsAccounts::cleanId('abcdefghij'))->toBeNull()->and(GoogleAdsAccounts::cleanId('12345678901'))->toBeNull();
});

it('connects: exchange, verify the customer, seal the refresh token, then save', function () {
    [$c, $h, $repo] = gadconn();
    array_push($h->queue, ['status' => 200, 'json' => ['access_token' => GAD_ACCESS, 'refresh_token' => GAD_REFRESH, 'scope' => 'https://www.googleapis.com/auth/adwords']], gadcustomer());
    expect($c->connect('TENANT0000000000000000000A', GAD_CUST, '9876543210', 'code', 'verifier', 'https://x.test/cb'))->toBe('ok');
    [$name, $a] = $repo->calls[0];
    expect($name)->toBe('save')->and($a[2])->toBe('আমার গুগল')->and($a[3])->toBe(GAD_CUST)->and($a[4])->toBe('9876543210')->and($a[5])->toBe('BDT')->and(str_contains($a[6], GAD_REFRESH))->toBeFalse()->and((new FakeSealer)->open($a[6], $a[7]))->toBe(GAD_REFRESH);
});

it('does not save when the exchange, the customer or the status is wrong', function () {
    foreach ([[[['status' => 400, 'json' => []]], 'failed'], [[['status' => 200, 'json' => ['access_token' => 'a', 'scope' => 'https://www.googleapis.com/auth/adwords']]], 'no_refresh'],
        [[['status' => 200, 'json' => ['access_token' => 'a', 'refresh_token' => 'r', 'scope' => 'https://www.googleapis.com/auth/adwords']], ['status' => 403, 'json' => ['error' => ['status' => 'PERMISSION_DENIED']]]], 'rejected'],
        [[['status' => 200, 'json' => ['access_token' => 'a', 'refresh_token' => 'r', 'scope' => 'https://www.googleapis.com/auth/adwords']], ['status' => 401, 'json' => ['error' => ['status' => 'UNAUTHENTICATED']]]], 'token'],
        [[['status' => 200, 'json' => ['access_token' => 'a', 'refresh_token' => 'r', 'scope' => 'https://www.googleapis.com/auth/adwords']], gadcustomer(['status' => 'CANCELED'])], 'inactive']] as [$queue, $want]) {
        [$c, $h, $repo] = gadconn();
        array_push($h->queue, ...$queue);
        expect($c->connect('TENANT0000000000000000000A', GAD_CUST, null, 'code', 'v', 'u'))->toBe($want)->and($repo->calls)->toBe([]);
    }
    [$c, $h, $repo] = gadconn();
    expect($c->connect('TENANT0000000000000000000A', '12ab', null, 'code', 'v', 'u'))->toBe('failed')->and($c->connect('TENANT0000000000000000000A', GAD_CUST, 'x', 'code', 'v', 'u'))->toBe('failed')->and($c->connect('TENANT0000000000000000000A', GAD_CUST, null, '', 'v', 'u'))->toBe('failed')->and(count($h->calls))->toBe(0);
});

it('reports the limit and storage failures', function () {
    [$c, $h, $repo] = gadconn();
    $repo->answer = 'limit';
    array_push($h->queue, ['status' => 200, 'json' => ['access_token' => 'a', 'refresh_token' => 'r', 'scope' => 'https://www.googleapis.com/auth/adwords']], gadcustomer());
    expect($c->connect('TENANT0000000000000000000A', GAD_CUST, null, 'code', 'v', 'u'))->toBe('limit');
    [$c, $h, $repo] = gadconn();
    $repo->throw = new RuntimeException('db');
    array_push($h->queue, ['status' => 200, 'json' => ['access_token' => 'a', 'refresh_token' => 'r', 'scope' => 'https://www.googleapis.com/auth/adwords']], gadcustomer());
    expect($c->connect('TENANT0000000000000000000A', GAD_CUST, null, 'code', 'v', 'u'))->toBe('failed');
    expect($c->disconnect('T', 'A'))->toBe('failed');
});

function gadsync(): array
{
    $h = new FakeHttpJson;
    $repo = new FakeGoogleAdsRepo;
    $repo->due = [['account_id' => 'ACC00000000000000000000AAA', 'tenant_id' => 'TENANT0000000000000000000A', 'customer_id' => GAD_CUST, 'login_id' => null, 'cipher' => (new FakeSealer)->seal(GAD_REFRESH)['cipher'], 'version' => 1]];

    return [new GoogleAdsSync($repo, new FakeSealer, new GoogleAdsApi($h, 'a', 'b', 'c', 'v24')), $h, $repo];
}

function gadok(): array
{
    return [['status' => 200, 'json' => ['access_token' => GAD_ACCESS]], ['status' => 200, 'json' => ['results' => [['campaign' => ['id' => '111', 'name' => 'a', 'status' => 'ENABLED']]]]], ['status' => 200, 'json' => ['results' => [['campaign' => ['id' => '111'], 'segments' => ['date' => '2026-11-01'], 'metrics' => ['costMicros' => '1000000']]]]]];
}

it('syncs: refresh, campaigns, results, store', function () {
    [$s, $h, $repo] = gadsync();
    array_push($h->queue, ...gadok());
    expect($s->run(3))->toBe(['looked' => 1, 'saved' => 1])->and($repo->names())->toBe(['due', 'store']);
    expect($repo->calls[1][1][2][0]['id'])->toBe('111')->and($repo->calls[1][1][3][0]['cost'])->toBe('1.00');
    expect($h->calls[1]['headers']['Authorization'])->toBe('Bearer '.GAD_ACCESS);
    expect(json_decode((string) $h->calls[2]['body'], true)['query'])->toContain("BETWEEN '".gmdate('Y-m-d', time() - 30 * 86400)."'");
});

it('a revoked grant, a rejected token or an unreadable token marks the account invalid; other problems only record a note', function () {
    [$s, $h, $repo] = gadsync();
    $h->queue[] = ['status' => 400, 'json' => ['error' => 'invalid_grant']];
    $s->run(3);
    expect($repo->names())->toBe(['due', 'fail'])->and($repo->calls[1][1][3])->toBeTrue();
    [$s, $h, $repo] = gadsync();
    $h->queue[] = ['status' => 200, 'json' => ['access_token' => GAD_ACCESS]];
    $h->queue[] = ['status' => 401, 'json' => ['error' => ['status' => 'UNAUTHENTICATED']]];
    $s->run(3);
    expect($repo->calls[1][1][3])->toBeTrue();
    [$s, $h, $repo] = gadsync();
    $h->queue[] = ['status' => 200, 'json' => ['access_token' => GAD_ACCESS]];
    $h->queue[] = ['status' => 200, 'json' => ['results' => []]];
    $h->queue[] = ['status' => 401, 'json' => ['error' => ['status' => 'UNAUTHENTICATED']]];
    $s->run(3);
    expect($repo->names())->toBe(['due', 'fail'])->and($repo->calls[1][1][3])->toBeTrue();
    [$s, $h, $repo] = gadsync();
    $h->queue[] = ['status' => 200, 'json' => ['access_token' => GAD_ACCESS]];
    $h->queue[] = ['status' => 500, 'json' => []];
    $h->queue[] = gadok()[2];
    expect($s->run(3)['saved'])->toBe(0);
    [$s, $h, $repo] = gadsync();
    $repo->due[0]['cipher'] = 'sealed:other:xx';
    $s->run(3);
    expect(count($h->calls))->toBe(0)->and($repo->calls[1][1][3])->toBeTrue();
    foreach ([[['status' => 500, 'json' => []]], [['status' => 200, 'json' => ['access_token' => GAD_ACCESS]], ['status' => 500, 'json' => []]], [['status' => 200, 'json' => ['access_token' => GAD_ACCESS]], ['status' => 200, 'json' => ['results' => []]], ['status' => 500, 'json' => []]]] as $q) {
        [$s, $h, $repo] = gadsync();
        array_push($h->queue, ...$q);
        expect($s->run(3)['saved'])->toBe(0)->and($repo->names())->toBe(['due', 'fail'])->and($repo->calls[1][1][3])->toBeFalse();
    }
});

it('a failing repository does not break the run', function () {
    [$s, $h, $repo] = gadsync();
    $repo->throw = new RuntimeException('db');
    expect($s->run(3))->toBe(['looked' => 0, 'saved' => 0]);
    [$s, $h, $repo] = gadsync();
    array_push($h->queue, ...gadok());
    $repo->answer = 'bad_input';
    expect($s->run(3))->toBe(['looked' => 1, 'saved' => 0]);
});
