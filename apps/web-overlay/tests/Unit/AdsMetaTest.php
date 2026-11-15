<?php

use App\Ads\AdsAccounts;
use App\Ads\MetaAdsApi;
use App\Ads\MetaAdsSync;
use App\Tenancy\TenantRoles;
use Tests\Support\FakeAdsRepo;
use Tests\Support\FakeHttpJson;
use Tests\Support\FakeSealer;

const ADS_T = 'TENANT0000000000000000000A';
const ADS_TOKEN = 'EAAB-ads-token_value-0123456789abcdef';
const ADS_ACC = '123456789';

function adsapi(): array
{
    $h = new FakeHttpJson;

    return [new MetaAdsApi($h, 'v24.0'), $h];
}

it('checks an ad account with the token in a header, never in the URL', function () {
    [$api, $h] = adsapi();
    $h->queue[] = ['status' => 200, 'json' => ['account_id' => ADS_ACC, 'name' => "আমার\nঅ্যাড", 'currency' => 'BDT', 'account_status' => 1]];
    expect($api->account(ADS_ACC, ADS_TOKEN))->toBe(['ok' => true, 'id' => ADS_ACC, 'name' => 'আমার অ্যাড', 'currency' => 'BDT', 'active' => true, 'token' => false, 'message' => '']);
    $c = $h->calls[0];
    expect($c['method'])->toBe('GET')->and($c['url'])->toStartWith('https://graph.facebook.com/v24.0/act_'.ADS_ACC.'?')->and($c['headers']['Authorization'])->toBe('Bearer '.ADS_TOKEN)->and(str_contains($c['url'], 'EAAB'))->toBeFalse()->and(str_contains($c['url'], 'access_token'))->toBeFalse();
    $h->queue[] = ['status' => 200, 'json' => ['account_id' => ADS_ACC, 'currency' => 'USD', 'account_status' => 2]];
    $r = $api->account(ADS_ACC, ADS_TOKEN);
    expect($r['ok'])->toBeTrue()->and($r['active'])->toBeFalse()->and($r['name'])->toBe('Meta Ads '.ADS_ACC);
});

it('refuses an account Meta did not describe, and tells a bad token apart', function () {
    [$api, $h] = adsapi();
    foreach ([['status' => 200, 'json' => []], ['status' => 200, 'json' => ['account_id' => ADS_ACC, 'currency' => 'bdt']], ['status' => 200, 'json' => ['currency' => 'BDT']], ['status' => 500, 'json' => ['account_id' => ADS_ACC, 'currency' => 'BDT']]] as $resp) {
        $h->queue[] = $resp;
        $r = $api->account(ADS_ACC, ADS_TOKEN);
        expect($r['ok'])->toBeFalse()->and($r['token'])->toBeFalse();
    }
    $h->queue[] = ['status' => 400, 'json' => ['error' => ['code' => 190, 'message' => "Token\nexpired"]]];
    $r = $api->account(ADS_ACC, ADS_TOKEN);
    expect($r['token'])->toBeTrue()->and($r['message'])->toBe('Meta: Token expired (Code 190)');
    $h->queue[] = ['status' => 400, 'json' => ['error' => ['code' => 100, 'message' => str_repeat('ক', 500)]]];
    $long = $api->account(ADS_ACC, ADS_TOKEN)['message'];
    expect(mb_strlen($long))->toBe(mb_strlen('Meta: ') + 200 + mb_strlen(' (কোড 100)'));
    $h->queue[] = ['status' => 400, 'json' => ['error' => ['code' => 100, 'message' => 'Unsupported get request']]];
    expect($api->account(ADS_ACC, ADS_TOKEN)['token'])->toBeFalse();
    $n = count($h->calls);
    expect($api->account('act_1', ADS_TOKEN)['ok'])->toBeFalse()->and($api->account('12', ADS_TOKEN)['ok'])->toBeFalse()->and(count($h->calls))->toBe($n);
    $thrower = new class implements App\Outreach\HttpJson {
        public function request(string $method, string $url, array $headers = [], ?array $form = null, ?string $body = null): array
        {
            throw new RuntimeException('net');
        }
    };
    $t = new MetaAdsApi($thrower);
    expect($t->account(ADS_ACC, 'x')['ok'])->toBeFalse()->and($t->campaigns(ADS_ACC, 'x')['status'])->toBe('error')->and($t->insights(ADS_ACC, 'x', 7)['status'])->toBe('error');
});

it('lists campaigns, cleaning names and skipping malformed rows', function () {
    [$api, $h] = adsapi();
    $h->queue[] = ['status' => 200, 'json' => ['data' => [['id' => '1234567', 'name' => "ঈদ\n অফার", 'objective' => 'OUTCOME_SALES', 'effective_status' => 'ACTIVE'], ['id' => 'x1', 'name' => 'bad'], 'junk', ['id' => '7654321']]]];
    $r = $api->campaigns(ADS_ACC, ADS_TOKEN);
    expect($r['status'])->toBe('ok')->and($r['items'])->toBe([['id' => '1234567', 'name' => 'ঈদ  অফার', 'objective' => 'OUTCOME_SALES', 'status' => 'ACTIVE'], ['id' => '7654321', 'name' => '', 'objective' => null, 'status' => 'UNKNOWN']]);
    expect($h->calls[0]['url'])->toContain('/act_'.ADS_ACC.'/campaigns?')->and($h->calls[0]['url'])->toContain('fields=id%2Cname%2Cobjective%2Ceffective_status')->and($h->calls[0]['headers']['Authorization'])->toBe('Bearer '.ADS_TOKEN);
    $h->queue[] = ['status' => 400, 'json' => ['error' => ['code' => 190]]];
    expect($api->campaigns(ADS_ACC, 'x'))->toBe(['status' => 'token', 'items' => []]);
    $h->queue[] = ['status' => 400, 'json' => ['error' => ['code' => 17]]];
    expect($api->campaigns(ADS_ACC, 'x')['status'])->toBe('error');
    $h->queue[] = ['status' => 200, 'json' => ['data' => 'nope']];
    expect($api->campaigns(ADS_ACC, 'x')['status'])->toBe('error');
});

it('follows the cursor, never a URL from the response, and stops after four pages', function () {
    [$api, $h] = adsapi();
    $h->queue[] = ['status' => 200, 'json' => ['data' => [['id' => '1111111', 'name' => 'a']], 'paging' => ['cursors' => ['after' => 'CUR1'], 'next' => 'https://evil.example/steal']]];
    $h->queue[] = ['status' => 200, 'json' => ['data' => [['id' => '2222222', 'name' => 'b']], 'paging' => ['cursors' => ['after' => 'CUR2']]]];
    $r = $api->campaigns(ADS_ACC, ADS_TOKEN);
    expect(array_column($r['items'], 'id'))->toBe(['1111111', '2222222'])->and($h->calls)->toHaveCount(2)->and($h->calls[1]['url'])->toContain('after=CUR1')->and($h->calls[1]['url'])->toStartWith('https://graph.facebook.com/');
    foreach (range(1, 10) as $i) {
        $h->queue[] = ['status' => 200, 'json' => ['data' => [], 'paging' => ['cursors' => ['after' => 'C'.$i], 'next' => 'x']]];
    }
    $n = count($h->calls);
    $api->campaigns(ADS_ACC, ADS_TOKEN);
    expect(count($h->calls) - $n)->toBe(4);
    $h->queue = [];
    $h->queue[] = ['status' => 200, 'json' => ['data' => [], 'paging' => ['cursors' => ['after' => 'bad cursor!'], 'next' => 'x']]];
    $n = count($h->calls);
    $api->campaigns(ADS_ACC, ADS_TOKEN);
    expect(count($h->calls) - $n)->toBe(1);
});

it('reads daily results per campaign and picks one result number, not a sum of overlaps', function () {
    [$api, $h] = adsapi();
    $h->queue[] = ['status' => 200, 'json' => ['data' => [
        ['campaign_id' => '1234567', 'date_start' => '2026-11-01', 'spend' => '12.34', 'impressions' => '1000', 'clicks' => '20', 'actions' => [['action_type' => 'link_click', 'value' => '20'], ['action_type' => 'purchase', 'value' => '3'], ['action_type' => 'omni_purchase', 'value' => '3']]],
        ['campaign_id' => '1234567', 'date_start' => '2026-11-02', 'spend' => '0', 'impressions' => '0', 'clicks' => '0'],
        ['date_start' => '2026-11-02'], 'junk']]];
    $r = $api->insights(ADS_ACC, ADS_TOKEN, 7);
    expect($r['status'])->toBe('ok')->and($r['items'])->toBe([['campaign' => '1234567', 'day' => '2026-11-01', 'spend' => '12.34', 'impressions' => '1000', 'clicks' => '20', 'results' => '3'], ['campaign' => '1234567', 'day' => '2026-11-02', 'spend' => '0', 'impressions' => '0', 'clicks' => '0', 'results' => '0']]);
    $u = $h->calls[0]['url'];
    expect($u)->toContain('/act_'.ADS_ACC.'/insights?')->and($u)->toContain('level=campaign')->and($u)->toContain('time_increment=1');
    parse_str((string) parse_url($u, PHP_URL_QUERY), $q);
    $tr = json_decode($q['time_range'], true);
    expect($tr['until'])->toBe(gmdate('Y-m-d'))->and($tr['since'])->toBe(gmdate('Y-m-d', time() - 7 * 86400));
    $h->queue[] = ['status' => 200, 'json' => ['data' => []]];
    $api->insights(ADS_ACC, ADS_TOKEN, 5000);
    parse_str((string) parse_url($h->calls[1]['url'], PHP_URL_QUERY), $q2);
    expect(json_decode($q2['time_range'], true)['since'])->toBe(gmdate('Y-m-d', time() - 90 * 86400));
    $h->queue[] = ['status' => 200, 'json' => ['data' => []]];
    $api->insights(ADS_ACC, ADS_TOKEN, 0);
    parse_str((string) parse_url($h->calls[2]['url'], PHP_URL_QUERY), $q3);
    expect(json_decode($q3['time_range'], true)['since'])->toBe(gmdate('Y-m-d', time() - 86400));
});

it('picks the result by priority and ignores bad values', function () {
    expect(MetaAdsApi::results([['action_type' => 'lead', 'value' => '4'], ['action_type' => 'purchase', 'value' => '2']]))->toBe('2')
        ->and(MetaAdsApi::results([['action_type' => 'omni_purchase', 'value' => '5'], ['action_type' => 'purchase', 'value' => '2']]))->toBe('5')
        ->and(MetaAdsApi::results([['action_type' => 'lead', 'value' => '4'], ['action_type' => 'lead', 'value' => '1']]))->toBe('5')
        ->and(MetaAdsApi::results([['action_type' => 'onsite_conversion.messaging_conversation_started_7d', 'value' => '9']]))->toBe('9')
        ->and(MetaAdsApi::results([['action_type' => 'purchase', 'value' => '-1']]))->toBe('0')
        ->and(MetaAdsApi::results([['action_type' => 'purchase', 'value' => '1.5']]))->toBe('0')
        ->and(MetaAdsApi::results([['action_type' => 'link_click', 'value' => '9']]))->toBe('0')
        ->and(MetaAdsApi::results(null))->toBe('0')->and(MetaAdsApi::results('x'))->toBe('0')->and(MetaAdsApi::results(['junk']))->toBe('0');
});

// ---------- connecting
function adsconn(): array
{
    $h = new FakeHttpJson;
    $repo = new FakeAdsRepo;

    return [new AdsAccounts($repo, new FakeSealer, new MetaAdsApi($h)), $repo, $h];
}

function adsok(FakeHttpJson $h): void
{
    $h->queue[] = ['status' => 200, 'json' => ['account_id' => ADS_ACC, 'name' => 'আমার অ্যাড', 'currency' => 'BDT', 'account_status' => 1]];
}

it('connects an account and stores only the sealed token', function () {
    [$a, $repo, $h] = adsconn();
    adsok($h);
    expect($a->connect(ADS_T, ' act_'.ADS_ACC.' ', ' '.ADS_TOKEN.' '))->toBe('ok');
    $c = $repo->calls[0];
    expect($c[0])->toBe('saveAccount')->and($c[1][0])->toBe(ADS_T)->and($c[1][2])->toBe('আমার অ্যাড')->and($c[1][3])->toBe(ADS_ACC)->and($c[1][4])->toBe('BDT')->and(str_contains(json_encode($c[1]), ADS_TOKEN))->toBeFalse()->and((new FakeSealer)->open($c[1][5], $c[1][6]))->toBe(ADS_TOKEN);
    expect($h->calls[0]['headers']['Authorization'])->toBe('Bearer '.ADS_TOKEN);
});

it('maps every way connecting can fail without saving', function () {
    [$a, $repo, $h] = adsconn();
    expect($a->connect(ADS_T, 'abc', ADS_TOKEN))->toBe('bad_account')->and($a->connect(ADS_T, '123', ADS_TOKEN))->toBe('bad_account')->and($a->connect(ADS_T, ADS_ACC, 'short'))->toBe('bad_token')->and($a->connect(ADS_T, ADS_ACC, str_repeat('a', 601)))->toBe('bad_token')->and($a->connect(ADS_T, ADS_ACC, 'has space in the token value here'))->toBe('bad_token')->and($h->calls)->toBe([]);
    $h->queue[] = ['status' => 400, 'json' => ['error' => ['code' => 190, 'message' => 'bad']]];
    $msg = null;
    expect($a->connect(ADS_T, ADS_ACC, ADS_TOKEN, $msg))->toBe('token')->and($msg)->toContain('bad');
    $h->queue[] = ['status' => 400, 'json' => ['error' => ['code' => 100]]];
    expect($a->connect(ADS_T, ADS_ACC, ADS_TOKEN))->toBe('rejected');
    $h->queue[] = ['status' => 200, 'json' => ['account_id' => '999999', 'currency' => 'BDT']];
    expect($a->connect(ADS_T, ADS_ACC, ADS_TOKEN))->toBe('mismatch')->and($repo->calls)->toBe([]);
    $repo->answer = 'limit';
    adsok($h);
    expect($a->connect(ADS_T, ADS_ACC, ADS_TOKEN))->toBe('limit');
    $repo->answer = 'ok';
    $repo->throw = new RuntimeException('db');
    adsok($h);
    expect($a->connect(ADS_T, ADS_ACC, ADS_TOKEN))->toBe('failed');
});

it('disconnects and survives a database error', function () {
    [$a, $repo] = adsconn();
    expect($a->disconnect(ADS_T, 'ACC'))->toBe('ok')->and($repo->calls[0])->toBe(['deleteAccount', [ADS_T, 'ACC']]);
    $repo->throw = new RuntimeException('db');
    expect($a->disconnect(ADS_T, 'ACC'))->toBe('failed');
});

// ---------- sync
function adsdue(FakeAdsRepo $repo, ?string $token = ADS_TOKEN): void
{
    $s = (new FakeSealer)->seal($token ?? 'x');
    $repo->due = [['account_id' => 'ACC00000000000000000000001', 'tenant_id' => ADS_T, 'external_id' => ADS_ACC, 'cipher' => $token === null ? 'garbage' : $s['cipher'], 'version' => $s['version']]];
}

function adssync(): array
{
    $h = new FakeHttpJson;
    $repo = new FakeAdsRepo;

    return [new MetaAdsSync($repo, new FakeSealer, new MetaAdsApi($h)), $repo, $h];
}

it('copies campaigns and results and stores them for the right workspace', function () {
    [$s, $repo, $h] = adssync();
    adsdue($repo);
    $h->queue[] = ['status' => 200, 'json' => ['data' => [['id' => '1234567', 'name' => 'ঈদ', 'objective' => 'OUTCOME_SALES', 'effective_status' => 'ACTIVE']]]];
    $h->queue[] = ['status' => 200, 'json' => ['data' => [['campaign_id' => '1234567', 'date_start' => '2026-11-01', 'spend' => '5', 'impressions' => '10', 'clicks' => '1']]]];
    expect($s->run(3))->toBe(['looked' => 1, 'saved' => 1]);
    expect($repo->calls[0])->toBe(['syncDue', [3, 60]]);
    $st = $repo->calls[1];
    expect($st[0])->toBe('store')->and($st[1][0])->toBe(ADS_T)->and($st[1][1])->toBe('ACC00000000000000000000001')->and($st[1][2][0])->toMatchArray(['id' => '1234567', 'name' => 'ঈদ', 'daily_budget' => null])->and($st[1][3][0]['spend'])->toBe('5')->and(in_array('fail', $repo->names(), true))->toBeFalse();
    expect(array_key_exists('daily_budget', $st[1][2][0]))->toBeTrue()->and($st[1][2][0]['daily_budget'])->toBeNull();
    parse_str((string) parse_url($h->calls[1]['url'], PHP_URL_QUERY), $q);
    expect(json_decode($q['time_range'], true)['since'])->toBe(gmdate('Y-m-d', time() - MetaAdsSync::DAYS * 86400))->and(MetaAdsSync::DAYS)->toBe(30);
    expect($h->calls)->toHaveCount(2)->and($h->calls[0]['headers']['Authorization'])->toBe('Bearer '.ADS_TOKEN);
    expect($s->run(3))->toBe(['looked' => 0, 'saved' => 0]);
});

it('records why a sync failed, and marks the account invalid only for a rejected token', function (array $queue, bool $invalid, string $contains, ?string $token) {
    [$s, $repo, $h] = adssync();
    adsdue($repo, $token);
    $h->queue = $queue;
    expect($s->run(3))->toBe(['looked' => 1, 'saved' => 0]);
    $f = array_values(array_filter($repo->calls, fn ($c) => $c[0] === 'fail'));
    expect($f)->toHaveCount(1)->and($f[0][1][0])->toBe(ADS_T)->and($f[0][1][1])->toBe('ACC00000000000000000000001')->and($f[0][1][2])->toContain($contains)->and($f[0][1][3])->toBe($invalid)->and(in_array('store', $repo->names(), true))->toBeFalse();
    if ($token === null) {
        expect($h->calls)->toBe([]);
    }
})->with([
    'campaigns: token' => [[['status' => 400, 'json' => ['error' => ['code' => 190]]]], true, 'আorর Connections', ADS_TOKEN],
    'campaigns: error' => [[['status' => 500, 'json' => []]], false, 'Campaign', ADS_TOKEN],
    'insights: token' => [[['status' => 200, 'json' => ['data' => []]], ['status' => 400, 'json' => ['error' => ['code' => 190]]]], true, 'আorর Connections', ADS_TOKEN],
    'insights: error' => [[['status' => 200, 'json' => ['data' => []]], ['status' => 500, 'json' => []]], false, 'Results', ADS_TOKEN],
    'token cannot be opened' => [[], true, 'টোকেন Open', null],
]);

it('does not crash when the database fails', function () {
    [$s, $repo, $h] = adssync();
    $repo->throw = new RuntimeException('db');
    expect($s->run(3))->toBe(['looked' => 0, 'saved' => 0]);
    [$s2, $repo2, $h2] = adssync();
    adsdue($repo2);
    $repo2->throw = new RuntimeException('db');
    $repo2->due = [];
    $repo2->throw = null;
    adsdue($repo2);
    $h2->queue[] = ['status' => 200, 'json' => ['data' => []]];
    $h2->queue[] = ['status' => 200, 'json' => ['data' => []]];
    $repo2->answer = 'bad_input';
    expect($s2->run(3))->toBe(['looked' => 1, 'saved' => 0]);
});

it('gives the Ads permissions to the right roles only', function () {
    foreach (['ads.view', 'ads.connect'] as $p) {
        expect(in_array($p, TenantRoles::PERMISSIONS, true))->toBeTrue()->and(in_array($p, TenantRoles::MATRIX['Owner'], true))->toBeTrue()->and(in_array($p, TenantRoles::MATRIX['Admin'], true))->toBeTrue();
    }
    foreach (['Manager', 'Auditor'] as $r) {
        expect(in_array('ads.view', TenantRoles::MATRIX[$r], true))->toBeTrue()->and(in_array('ads.connect', TenantRoles::MATRIX[$r], true))->toBeFalse();
    }
    expect(in_array('ads.view', TenantRoles::MATRIX['Member'], true))->toBeFalse()->and(in_array('ads.connect', TenantRoles::MATRIX['Member'], true))->toBeFalse();
});
