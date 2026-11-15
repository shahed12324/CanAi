<?php

use App\GoogleAds\GadsAdvisor;
use App\GoogleAds\GadsPauser;
use App\GoogleAds\GoogleAdsApi;
use App\GoogleAds\GoogleAdsLaunchApi;
use Tests\Support\FakeGadsAdviceRepo;
use Tests\Support\FakeGoogleAdsRepo;
use Tests\Support\FakeHttpJson;
use Tests\Support\FakeSealer;

const GAV_TOKEN = '1//advice-refresh_0123456789abcdef';
const GAV_CUST = '1234567890';
const GAV_ACC = 'ACC00000000000000000000AAA';

function gavrow(string $c, string $cost, int $clicks, string $conv, int $days = 5, string $status = 'ENABLED', string $acc = GAV_ACC): array
{
    return ['account_id' => $acc, 'campaign' => $c, 'name' => 'ক্যাম্পেইন '.$c, 'status' => $status, 'cost' => $cost, 'clicks' => $clicks, 'conversions' => $conv, 'days' => $days];
}

function gavkinds(array $rows, string $only = '100001'): array
{
    return array_values(array_map(fn ($a) => $a['kind'].':'.$a['campaign'], array_filter(GadsAdvisor::advise($rows), fn ($a) => $a['campaign'] === $only)));
}

it('suggests pausing only where conversions are being tracked in the account', function () {
    $tracked = gavrow('100009', '50.00', 10, '4.00'); // another campaign shows tracking works
    expect(gavkinds([$tracked, gavrow('100001', '800.00', 40, '0')]))->toBe(['pause:100001']);
    expect(gavkinds([gavrow('100001', '800.00', 40, '0')]))->toBe([]);                 // no conversions anywhere: tracking probably not set up
    expect(gavkinds([$tracked, gavrow('100001', '800.00', 29, '0')]))->toBe([]);
    expect(gavkinds([$tracked, gavrow('100001', '800.00', 400, '0', 2)]))->toBe([]);
    expect(gavkinds([$tracked, gavrow('100001', '800.00', 400, '0', 5, 'PAUSED')]))->toBe([]);
    expect(gavkinds([$tracked, gavrow('100001', '0.00', 400, '0')]))->toBe([]);
    expect(gavkinds([gavrow('100009', '50.00', 10, '4.00', 5, 'ENABLED', 'ACC00000000000000000000BBB'), gavrow('100001', '800.00', 40, '0')]))->toBe([]); // tracking in ANOTHER account does not count
});

it('rounds fractional conversions up so a partial result is never "no result"', function () {
    $tracked = gavrow('100009', '50.00', 10, '4.00');
    expect(gavkinds([$tracked, gavrow('100001', '800.00', 400, '0.40')]))->toBe([]);
});

it('compares only inside the account, needs two others, and scales only when clearly cheap', function () {
    $base = [gavrow('100002', '100.00', 50, '10.00'), gavrow('100003', '100.00', 50, '10.00')];
    expect(gavkinds([...$base, gavrow('100001', '300.00', 90, '10.00')]))->toBe(['pause:100001']);
    expect(gavkinds([...$base, gavrow('100001', '240.00', 90, '10.00')]))->toBe([]);
    expect(gavkinds([...$base, gavrow('100001', '250.00', 90, '10.00')]))->toBe(['pause:100001']);
    expect(gavkinds([gavrow('100002', '100.00', 50, '10.00'), gavrow('100001', '900.00', 90, '10.00')]))->toBe([]);
    expect(gavkinds([...$base, gavrow('100001', '30.00', 40, '5.00')]))->toBe(['scale:100001']);
    expect(gavkinds([...$base, gavrow('100001', '24.00', 40, '4.00')]))->toBe([]);
    $a = GadsAdvisor::advise([...$base, gavrow('100001', '30.00', 40, '5.00'), gavrow('100004', '500.00', 80, '0')]);
    expect($a[0]['kind'])->toBe('pause')->and(end($a)['kind'])->toBe('scale')->and($a[0]['why'] !== '')->toBeTrue();
});

it('sends only a PAUSED status update with a fixed mask, tokens in headers', function () {
    $h = new FakeHttpJson;
    $api = new GoogleAdsLaunchApi($h, 'devtok', 'v24');
    $h->queue[] = ['status' => 200, 'json' => ['results' => [['resourceName' => 'customers/'.GAV_CUST.'/campaigns/9000000001']]]];
    expect($api->pauseCampaign(GAV_CUST, '9876543210', 'ACCESS1', '9000000001')->status)->toBe('ok');
    $c = $h->calls[0];
    expect($c['url'])->toBe('https://googleads.googleapis.com/v24/customers/'.GAV_CUST.'/campaigns:mutate')->and($c['headers']['Authorization'])->toBe('Bearer ACCESS1')->and($c['headers']['login-customer-id'])->toBe('9876543210')
        ->and(json_decode($c['body'], true))->toBe(['operations' => [['updateMask' => 'status', 'update' => ['resourceName' => 'customers/'.GAV_CUST.'/campaigns/9000000001', 'status' => 'PAUSED']]]])
        ->and(str_contains($c['url'].$c['body'], 'ACCESS1'))->toBeFalse();
});

it('classifies pause answers; a 200 without an id is not trusted', function () {
    $h = new FakeHttpJson;
    $api = new GoogleAdsLaunchApi($h, 'devtok', 'v24');
    foreach ([[['status' => 200, 'json' => []], 'unknown'], [['status' => 500, 'json' => []], 'unknown'], [['status' => 401, 'json' => ['error' => ['status' => 'UNAUTHENTICATED']]], 'token'], [['status' => 400, 'json' => ['error' => ['message' => 'no']]], 'rejected'],
        [['status' => 200, 'json' => ['results' => [['resourceName' => 'customers/1234567890/campaigns/9000000001']]]], 'ok']] as [$resp, $want]) {
        $h->queue[] = $resp;
        expect($api->pauseCampaign(GAV_CUST, null, 'A', '9000000001')->status)->toBe($want);
    }
    $n = count($h->calls);
    expect($api->pauseCampaign(GAV_CUST, null, 'A', '9x')->status)->toBe('rejected')->and(count($h->calls))->toBe($n);
});

function gavpauser(): array
{
    $h = new FakeHttpJson;
    $repo = new FakeGadsAdviceRepo;
    $acc = new FakeGoogleAdsRepo;
    $repo->due = [['req_id' => 'REQ00000000000000000000AAA', 'tenant_id' => 'TENANT0000000000000000000A', 'account_id' => GAV_ACC, 'customer_id' => GAV_CUST, 'login_id' => null, 'campaign' => '9000000001', 'cipher' => (new FakeSealer)->seal(GAV_TOKEN)['cipher'], 'version' => 1]];

    return [new GadsPauser($repo, $acc, new FakeSealer, new GoogleAdsApi($h, 'cid', 'csec', 'devtok', 'v24'), new GoogleAdsLaunchApi($h, 'devtok', 'v24')), $h, $repo, $acc];
}

function gavaccess(): array
{
    return ['status' => 200, 'json' => ['access_token' => 'ya29.x']];
}

it('the worker pauses and reports success', function () {
    [$p, $h, $repo] = gavpauser();
    array_push($h->queue, gavaccess(), ['status' => 200, 'json' => ['results' => [['resourceName' => 'customers/1234567890/campaigns/9000000001']]]]);
    expect($p->run(3))->toBe(['looked' => 1, 'paused' => 1])->and($repo->names())->toBe(['claim', 'done'])->and($repo->calls[1][1][2])->toBeTrue()->and($h->calls[1]['url'])->toEndWith('/campaigns:mutate');
});

it('the worker reports failures, and marks the account invalid on a token problem', function () {
    [$p, $h, $repo, $acc] = gavpauser();
    array_push($h->queue, gavaccess(), ['status' => 401, 'json' => ['error' => ['status' => 'UNAUTHENTICATED']]]);
    expect($p->run(3)['paused'])->toBe(0)->and($repo->calls[1][1][2])->toBeFalse()->and($acc->names())->toBe(['fail'])->and($acc->calls[0][1][3])->toBeTrue();
    [$p, $h, $repo, $acc] = gavpauser();
    array_push($h->queue, gavaccess(), ['status' => 400, 'json' => ['error' => ['message' => 'Not allowed here']]]);
    $p->run(3);
    expect($repo->calls[1][1][2])->toBeFalse()->and(str_contains($repo->calls[1][1][3], 'Not allowed here'))->toBeTrue()->and($acc->names())->toBe([]);
    [$p, $h, $repo, $acc] = gavpauser();
    array_push($h->queue, gavaccess(), ['status' => 503, 'json' => []]);
    $p->run(3);
    expect($repo->calls[1][1][2])->toBeFalse()->and(str_contains($repo->calls[1][1][3], 'ফল জানা যায়নি'))->toBeTrue();
    [$p, $h, $repo, $acc] = gavpauser();
    $h->queue[] = ['status' => 400, 'json' => ['error' => 'invalid_grant']];
    $p->run(3);
    expect($repo->calls[1][1][2])->toBeFalse()->and($acc->names())->toBe(['fail'])->and(count($h->calls))->toBe(1);
    [$p, $h, $repo, $acc] = gavpauser();
    $h->queue[] = ['status' => 500, 'json' => []];
    $p->run(3);
    expect($repo->calls[1][1][2])->toBeFalse()->and($acc->names())->toBe([])->and(count($h->calls))->toBe(1);
});

it('an unopenable token makes no Google call; a failing claim does nothing', function () {
    [$p, $h, $repo] = gavpauser();
    $repo->due[0]['cipher'] = 'sealed:other:xx';
    expect($p->run(3)['paused'])->toBe(0)->and(count($h->calls))->toBe(0)->and($repo->calls[1][1][2])->toBeFalse();
    [$p, $h, $repo] = gavpauser();
    $repo->throw = new RuntimeException('db down');
    expect($p->run(3))->toBe(['looked' => 0, 'paused' => 0])->and(count($h->calls))->toBe(0);
});
