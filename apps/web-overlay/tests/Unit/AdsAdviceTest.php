<?php

use App\Ads\AdsAdvisor;
use App\Ads\AdsPauser;
use App\Ads\MetaAdsLaunchApi;
use Tests\Support\FakeAdsAdviceRepo;
use Tests\Support\FakeAdsRepo;
use Tests\Support\FakeHttpJson;
use Tests\Support\FakeSealer;

const ADV_TOKEN = 'EAAB-advice-token_0123456789abcdef';

function advrow(string $c, string $spend, int $clicks, int $results, int $days = 5, string $status = 'ACTIVE', string $acc = 'ACC00000000000000000000AAA'): array
{
    return ['account_id' => $acc, 'campaign' => $c, 'name' => 'ক্যাম্পেইন '.$c, 'status' => $status, 'spend' => $spend, 'clicks' => $clicks, 'results' => $results, 'days' => $days];
}

function advkinds(array $rows, string $only = '100001'): array
{
    return array_values(array_map(fn ($a) => $a['kind'].':'.$a['campaign'], array_filter(AdsAdvisor::advise($rows), fn ($a) => $a['campaign'] === $only)));
}

it('suggests pausing a campaign with enough clicks and no results', function () {
    expect(advkinds([advrow('100001', '800.00', 40, 0)]))->toBe(['pause:100001']);
    expect(advkinds([advrow('100001', '800.00', 29, 0)]))->toBe([]);
    expect(advkinds([advrow('100001', '800.00', 400, 0, 2)]))->toBe([]);
    expect(advkinds([advrow('100001', '800.00', 400, 0, 5, 'PAUSED')]))->toBe([]);
    expect(advkinds([advrow('100001', '0.00', 400, 0)]))->toBe([]);
});

it('compares only with other campaigns of the same account, and needs two of them', function () {
    $base = [advrow('100002', '100.00', 50, 10), advrow('100003', '100.00', 50, 10)]; // others' average cost 10
    expect(advkinds([...$base, advrow('100001', '300.00', 90, 10)]))->toBe(['pause:100001']);   // cost 30 = 3x
    expect(advkinds([...$base, advrow('100001', '240.00', 90, 10)]))->toBe([]);                 // cost 24 = 2.4x: below 2.5
    expect(advkinds([...$base, advrow('100001', '250.00', 90, 10)]))->toBe(['pause:100001']);   // exactly 2.5x
    expect(advkinds([advrow('100002', '100.00', 50, 10), advrow('100001', '900.00', 90, 10)]))->toBe([]);   // only one other: no benchmark
    expect(advkinds([...$base, advrow('100001', '300.00', 90, 10, 5, 'ACTIVE', 'ACC00000000000000000000BBB')]))->toBe([]); // other account
});

it('suggests scaling only for clearly cheap campaigns with enough results', function () {
    $base = [advrow('100002', '100.00', 50, 10), advrow('100003', '100.00', 50, 10)];
    expect(advkinds([...$base, advrow('100001', '30.00', 40, 5)]))->toBe(['scale:100001']);     // cost 6 = 0.6x
    expect(advkinds([...$base, advrow('100001', '31.00', 40, 5)]))->toBe([]);                   // 6.2 = 0.62x
    expect(advkinds([...$base, advrow('100001', '24.00', 40, 4)]))->toBe([]);                   // only 4 results
});

it('lists pause advice before scale advice and gives a reason', function () {
    $rows = [advrow('100002', '100.00', 50, 10), advrow('100003', '100.00', 50, 10), advrow('100001', '30.00', 40, 5), advrow('100004', '500.00', 80, 0)];
    $a = AdsAdvisor::advise($rows);
    $k = array_column($a, 'kind');
    expect($k[0])->toBe('pause')->and(end($k))->toBe('scale')->and($a[0]['campaign'])->toBe('100004');
    $seenScale = false;
    foreach ($k as $x) {
        expect($seenScale && $x === 'pause')->toBeFalse();
        $seenScale = $seenScale || $x === 'scale';
    }
    expect(is_string($a[0]['why']) && $a[0]['why'] !== '')->toBeTrue();
});

it('sends only PAUSED to the campaign, token in the header', function () {
    $h = new FakeHttpJson;
    $api = new MetaAdsLaunchApi($h, 'v24.0');
    $h->queue[] = ['status' => 200, 'json' => ['success' => true]];
    expect($api->pauseCampaign('900000001', ADV_TOKEN)->status)->toBe('ok');
    $c = $h->calls[0];
    expect($c['method'])->toBe('POST')->and($c['url'])->toBe('https://graph.facebook.com/v24.0/900000001')->and($c['form'])->toBe(['status' => 'PAUSED'])->and($c['headers']['Authorization'])->toBe('Bearer '.ADV_TOKEN)->and(str_contains($c['url'], 'EAAB'))->toBeFalse();
});

it('classifies pause answers; a 200 without success is not trusted', function () {
    $h = new FakeHttpJson;
    $api = new MetaAdsLaunchApi($h, 'v24.0');
    foreach ([[['status' => 200, 'json' => []], 'unknown'], [['status' => 200, 'json' => ['success' => false]], 'unknown'], [['status' => 500, 'json' => []], 'unknown'], [['status' => 400, 'json' => ['error' => ['code' => 190, 'message' => 'x']]], 'token'], [['status' => 400, 'json' => ['error' => ['code' => 100, 'message' => 'no']]], 'rejected']] as [$resp, $want]) {
        $h->queue[] = $resp;
        expect($api->pauseCampaign('900000001', ADV_TOKEN)->status)->toBe($want);
    }
    $n = count($h->calls);
    expect($api->pauseCampaign('9x', ADV_TOKEN)->status)->toBe('rejected')->and(count($h->calls))->toBe($n);
});

function advpauser(): array
{
    $h = new FakeHttpJson;
    $repo = new FakeAdsAdviceRepo;
    $acc = new FakeAdsRepo;
    $repo->due = [['req_id' => 'REQ00000000000000000000AAA', 'tenant_id' => 'TENANT0000000000000000000A', 'account_id' => 'ACC00000000000000000000AAA', 'account_ext' => '123456789', 'campaign' => '900000001', 'cipher' => (new FakeSealer)->seal(ADV_TOKEN)['cipher'], 'version' => 1]];

    return [new AdsPauser($repo, $acc, new FakeSealer, new MetaAdsLaunchApi($h, 'v24.0')), $h, $repo, $acc];
}

it('the worker pauses and reports success', function () {
    [$p, $h, $repo] = advpauser();
    $h->queue[] = ['status' => 200, 'json' => ['success' => true]];
    expect($p->run(3))->toBe(['looked' => 1, 'paused' => 1])->and($repo->names())->toBe(['claim', 'done'])->and($repo->calls[1][1][2])->toBeTrue();
});

it('the worker reports failures, and marks the account invalid on a token error', function () {
    [$p, $h, $repo, $acc] = advpauser();
    $h->queue[] = ['status' => 400, 'json' => ['error' => ['code' => 190, 'message' => 'bad']]];
    expect($p->run(3)['paused'])->toBe(0)->and($repo->calls[1][1][2])->toBeFalse()->and($acc->names())->toBe(['fail'])->and($acc->calls[0][1][3])->toBeTrue();
    [$p, $h, $repo, $acc] = advpauser();
    $h->queue[] = ['status' => 400, 'json' => ['error' => ['code' => 100, 'message' => 'Not allowed here']]];
    $p->run(3);
    expect($repo->calls[1][1][2])->toBeFalse()->and(str_contains($repo->calls[1][1][3], 'Not allowed here'))->toBeTrue()->and($acc->names())->toBe([]);
    [$p, $h, $repo, $acc] = advpauser();
    $h->queue[] = ['status' => 503, 'json' => []];
    $p->run(3);
    expect($repo->calls[1][1][2])->toBeFalse();
});

it('an unopenable token makes no Meta call; a failing claim does nothing', function () {
    [$p, $h, $repo, $acc] = advpauser();
    $repo->due[0]['cipher'] = 'sealed:other:xx';
    expect($p->run(3)['paused'])->toBe(0)->and(count($h->calls))->toBe(0)->and($repo->calls[1][1][2])->toBeFalse();
    [$p, $h, $repo] = advpauser();
    $repo->throw = new RuntimeException('db down');
    expect($p->run(3))->toBe(['looked' => 0, 'paused' => 0])->and(count($h->calls))->toBe(0);
});
