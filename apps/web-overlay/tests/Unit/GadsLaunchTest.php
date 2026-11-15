<?php

use App\GoogleAds\GadsLauncher;
use App\GoogleAds\GadsLaunchPlan;
use App\GoogleAds\GoogleAdsApi;
use App\GoogleAds\GoogleAdsLaunchApi;
use Tests\Support\FakeAdsLaunchRepo;
use Tests\Support\FakeGoogleAdsRepo;
use Tests\Support\FakeHttpJson;
use Tests\Support\FakeSealer;

const GLC_TOKEN = '1//refresh-token_0123456789abcdef';
const GLC_NOW = 1800000000; // 2027-01-15 UTC
const GLC_CUST = '1234567890';

function glcrow(array $o = []): array
{
    return $o + ['id' => 'DRAFT000000000000000ABCDEF', 'tenant_id' => 'TENANT0000000000000000000A', 'account_id' => 'ACC00000000000000000000AAA', 'customer_id' => GLC_CUST, 'login_id' => null, 'currency' => 'BDT',
        'cipher' => (new FakeSealer)->seal(GLC_TOKEN)['cipher'], 'version' => 1, 'name' => 'ঈদ সার্চ', 'country' => 'BD', 'language' => 'bn', 'final_url' => 'https://shop.example.com/eid',
        'headlines' => ['ঈদ অফার', 'সেরা কাজু বাদাম', 'ঢাকায় হোম ডেলিভারি'], 'descriptions' => ['ঈদে সেরা কাজু বাদাম এখনই অর্ডার করুন।', 'সারা ঢাকায় দ্রুত ডেলিভারি।'],
        'keywords' => [['t' => 'কাজু বাদাম', 'm' => 'phrase'], ['t' => 'কাজু কিনুন', 'm' => 'exact']], 'negatives' => ['free'], 'daily_budget' => '500.00', 'days' => 7, 'start_date' => '2027-01-20',
        'remote_budget' => null, 'remote_campaign' => null, 'remote_adgroup' => null, 'remote_ad' => null, 'keywords_done' => false, 'targeting_done' => false];
}

function glcmake(): array
{
    $h = new FakeHttpJson;
    $repo = new FakeAdsLaunchRepoG;
    $acc = new FakeGoogleAdsRepo;
    $auth = new GoogleAdsApi($h, 'cid', 'csec', 'devtok', 'v24');

    return [new GadsLauncher($repo, $acc, new FakeSealer, $auth, new GoogleAdsLaunchApi($h, 'devtok', 'v24')), $h, $repo, $acc];
}

function glcaccess(): array
{
    return ['status' => 200, 'json' => ['access_token' => 'ya29.access-token-xyz']];
}

function glcres(string $kind, string $id): array
{
    return ['status' => 200, 'json' => ['results' => [['resourceName' => 'customers/'.GLC_CUST.'/'.$kind.'/'.$id]]]];
}

function glcmany(int $n): array
{
    return ['status' => 200, 'json' => ['results' => array_fill(0, $n, ['resourceName' => 'customers/'.GLC_CUST.'/x/1'])]];
}

function glcall(FakeHttpJson $h): void
{
    array_push($h->queue, glcaccess(), glcres('campaignBudgets', '5000001'), glcres('campaigns', '5000002'), glcres('adGroups', '5000003'), glcres('adGroupAds', '5000003~5000004'), glcmany(2), glcmany(3));
}

/** the repo double of 24.1c has the same shape */
class FakeAdsLaunchRepoG extends FakeAdsLaunchRepo implements App\GoogleAds\GadsLaunchRepo {}

it('converts the budget to micros with integer math', function () {
    expect(GadsLaunchPlan::micros('500.00'))->toBe('500000000')->and(GadsLaunchPlan::micros('0.5'))->toBe('500000')->and(GadsLaunchPlan::micros('12.34'))->toBe('12340000')->and(GadsLaunchPlan::micros('1'))->toBe('1000000')
        ->and(GadsLaunchPlan::micros('0'))->toBeNull()->and(GadsLaunchPlan::micros('0.00'))->toBeNull()->and(GadsLaunchPlan::micros('-5'))->toBeNull()->and(GadsLaunchPlan::micros('1.234'))->toBeNull()->and(GadsLaunchPlan::micros('abc'))->toBeNull();
});

it('plans a paused Search campaign with an end date that keeps the approved total', function () {
    $p = GadsLaunchPlan::build(glcrow(), GLC_NOW);
    expect($p['error'])->toBeNull()->and($p['budget']['amountMicros'])->toBe('500000000')->and($p['budget']['explicitlyShared'])->toBeFalse()->and($p['campaign']['advertisingChannelType'])->toBe('SEARCH')
        ->and($p['campaign']['startDate'])->toBe('2027-01-20')->and($p['campaign']['endDate'])->toBe('2027-01-26')->and($p['campaign']['networkSettings']['targetContentNetwork'])->toBeFalse()->and($p['campaign']['networkSettings']['targetSearchNetwork'])->toBeFalse()
        ->and(str_ends_with($p['campaign']['name'], ' [J1-ABCDEF]'))->toBeTrue()->and($p['budget']['name'])->toBe('Budget '.$p['campaign']['name']);
    expect(count($p['ad']['responsiveSearchAd']['headlines']))->toBe(3)->and($p['ad']['finalUrls'])->toBe(['https://shop.example.com/eid']);
    expect($p['keywords'])->toBe([['text' => 'কাজু বাদাম', 'matchType' => 'PHRASE'], ['text' => 'কাজু কিনুন', 'matchType' => 'EXACT']]);
    expect(str_contains(json_encode($p), 'BROAD'))->toBeFalse()->and(str_contains(json_encode($p), 'ENABLED'))->toBeFalse();
    expect($p['targeting'][0]['location']['geoTargetConstant'])->toBe('geoTargetConstants/2050')->and($p['targeting'][1]['language']['languageConstant'])->toBe('languageConstants/1056')
        ->and($p['targeting'][2])->toBe(['negative' => true, 'keyword' => ['text' => 'free', 'matchType' => 'PHRASE']]);
});

it('starts today when the chosen date has passed, and strips exact brackets', function () {
    $p = GadsLaunchPlan::build(glcrow(['start_date' => '2020-01-01', 'days' => 3, 'keywords' => [['t' => '[কাজু কিনুন]', 'm' => 'exact']]]), GLC_NOW);
    expect($p['campaign']['startDate'])->toBe('2027-01-15')->and($p['campaign']['endDate'])->toBe('2027-01-17')->and($p['keywords'][0]['text'])->toBe('কাজু কিনুন');
    expect(GadsLaunchPlan::build(glcrow(['days' => 1]), GLC_NOW)['campaign']['endDate'])->toBe('2027-01-20');
});

it('refuses what it cannot create correctly, before any call', function () {
    $long = str_repeat('ক', 31);
    foreach ([['customer_id' => '12'], ['final_url' => 'http://x.example.com'], ['final_url' => ''], ['country' => 'FR'], ['language' => 'xx'], ['daily_budget' => '0.00'], ['start_date' => null], ['days' => 0], ['days' => 61],
        ['headlines' => ['a', 'b']], ['headlines' => ['a', 'b', $long]], ['headlines' => ['a', 'b', 'c<d']], ['descriptions' => ['only one']], ['descriptions' => ['a', 'b', 'c', 'd', 'e']],
        ['keywords' => []], ['keywords' => array_fill(0, 31, ['t' => 'x', 'm' => 'phrase'])], ['keywords' => [['t' => 'x', 'm' => 'broad']]], ['keywords' => [['t' => 'x!y', 'm' => 'phrase']]], ['keywords' => [['t' => '', 'm' => 'phrase']]], ['keywords' => [['t' => str_repeat('a', 81), 'm' => 'phrase']]],
        ['negatives' => ['bad@one']], ['negatives' => [str_repeat('a', 81)]], ['headlines' => 'text']] as $o) {
        $p = GadsLaunchPlan::build(glcrow($o), GLC_NOW);
        expect(is_string($p['error']))->toBeTrue()->and($p['campaign'])->toBe([]);
    }
});

it('forces PAUSED on the campaign and sends only create operations with tokens in headers', function () {
    $h = new FakeHttpJson;
    $api = new GoogleAdsLaunchApi($h, 'devtok', 'v24');
    $h->queue[] = glcres('campaigns', '5000002');
    $r = $api->campaign(GLC_CUST, '9876543210', 'ACCESS1', '5000001', ['name' => 'x', 'status' => 'ENABLED']);
    expect($r->status)->toBe('ok')->and($r->id)->toBe('5000002');
    $c = $h->calls[0];
    expect($c['url'])->toBe('https://googleads.googleapis.com/v24/customers/'.GLC_CUST.'/campaigns:mutate')->and($c['headers']['Authorization'])->toBe('Bearer ACCESS1')->and($c['headers']['developer-token'])->toBe('devtok')->and($c['headers']['login-customer-id'])->toBe('9876543210');
    $body = json_decode($c['body'], true);
    expect($body['operations'])->toHaveCount(1)->and(array_keys($body['operations'][0]))->toBe(['create'])->and($body['operations'][0]['create']['status'])->toBe('PAUSED')
        ->and($body['operations'][0]['create']['campaignBudget'])->toBe('customers/'.GLC_CUST.'/campaignBudgets/5000001');
    expect(str_contains($c['url'], 'ACCESS1'))->toBeFalse()->and(str_contains($c['body'], 'ACCESS1'))->toBeFalse();
});

it('builds each step against the right service and resource', function () {
    $h = new FakeHttpJson;
    $api = new GoogleAdsLaunchApi($h, 'devtok', 'v24');
    array_push($h->queue, glcres('campaignBudgets', '5000001'), glcres('adGroups', '5000003'), glcres('adGroupAds', '5000003~5000004'), glcmany(2), glcmany(2));
    expect($api->budget(GLC_CUST, null, 'A', ['name' => 'b'])->id)->toBe('5000001');
    expect($api->adGroup(GLC_CUST, null, 'A', '5000002', ['name' => 'g'])->id)->toBe('5000003');
    expect($api->ad(GLC_CUST, null, 'A', '5000003', ['finalUrls' => ['https://x.example.com']])->id)->toBe('5000004'); // Google names an ad "adGroupId~adId"
    expect($api->keywords(GLC_CUST, null, 'A', '5000003', [['text' => 'a', 'matchType' => 'PHRASE'], ['text' => 'b', 'matchType' => 'EXACT']])->id)->toBe('done');
    expect($api->targeting(GLC_CUST, null, 'A', '5000002', [['negative' => true, 'keyword' => ['text' => 'x', 'matchType' => 'PHRASE']], ['location' => ['geoTargetConstant' => 'geoTargetConstants/2050']]])->id)->toBe('done');
    $urls = array_column($h->calls, 'url');
    expect($urls[0])->toEndWith('/campaignBudgets:mutate')->and($urls[1])->toEndWith('/adGroups:mutate')->and($urls[2])->toEndWith('/adGroupAds:mutate')->and($urls[3])->toEndWith('/adGroupCriteria:mutate')->and($urls[4])->toEndWith('/campaignCriteria:mutate');
    $g = json_decode($h->calls[1]['body'], true)['operations'][0]['create'];
    expect($g['campaign'])->toBe('customers/'.GLC_CUST.'/campaigns/5000002')->and($g['status'])->toBe('ENABLED');
    $k = json_decode($h->calls[3]['body'], true)['operations'];
    expect($k)->toHaveCount(2)->and($k[0]['create']['adGroup'])->toBe('customers/'.GLC_CUST.'/adGroups/5000003')->and($k[1]['create']['keyword']['matchType'])->toBe('EXACT');
    $t = json_decode($h->calls[4]['body'], true)['operations'];
    expect($t[0]['create']['campaign'])->toBe('customers/'.GLC_CUST.'/campaigns/5000002')->and($t[0]['create']['negative'])->toBeTrue();
});

it('classifies Google answers: ok, token, rejected, unknown', function () {
    $h = new FakeHttpJson;
    $api = new GoogleAdsLaunchApi($h, 'devtok', 'v24');
    $call = fn () => $api->budget(GLC_CUST, null, 'A', ['name' => 'b']);
    $h->queue[] = ['status' => 401, 'json' => ['error' => ['code' => 401, 'status' => 'UNAUTHENTICATED']]];
    expect($call()->status)->toBe('token');
    $h->queue[] = ['status' => 403, 'json' => ['error' => ['code' => 403, 'status' => 'PERMISSION_DENIED', 'message' => 'x']]];
    $r = $call();
    expect($r->status)->toBe('rejected')->and(str_contains($r->message, 'Developer Token'))->toBeTrue();
    $h->queue[] = ['status' => 400, 'json' => ['error' => ['code' => 400, 'status' => 'INVALID_ARGUMENT', 'message' => 'Request contains an invalid argument.', 'details' => [['errors' => [['message' => "Budget\ntoo low"]]]]]]];
    $r = $call();
    expect($r->status)->toBe('rejected')->and(str_contains($r->message, 'Budget too low'))->toBeTrue();
    $h->queue[] = ['status' => 400, 'json' => ['error' => ['code' => 400, 'message' => 'plain']]];
    expect(str_contains($call()->message, 'plain'))->toBeTrue();
    $h->queue[] = ['status' => 429, 'json' => ['error' => ['code' => 429, 'status' => 'RESOURCE_EXHAUSTED']]];
    expect($call()->status)->toBe('rejected');
    $h->queue[] = ['status' => 500, 'json' => []];
    expect($call()->status)->toBe('unknown');
    $h->queue[] = ['status' => 503, 'json' => ['error' => ['code' => 503, 'message' => 'unavailable']]];
    expect($call()->status)->toBe('unknown');
    $h->queue[] = ['status' => 400, 'json' => []];
    expect($call()->status)->toBe('unknown');
    $h->queue[] = ['status' => 200, 'json' => []];
    expect($call()->status)->toBe('unknown');
    $h->queue[] = ['status' => 200, 'json' => ['results' => [['resourceName' => 'customers/1234567890/campaignBudgets/abc']]]];
    expect($call()->status)->toBe('unknown');
    $h->queue[] = ['status' => 200, 'json' => ['results' => [['resourceName' => 'customers/1234567890/campaignBudgets/5000001'], ['resourceName' => 'customers/1234567890/campaignBudgets/5000002']]]];
    expect($call()->status)->toBe('unknown');
    $h->queue[] = glcmany(1);
    expect($api->keywords(GLC_CUST, null, 'A', '5000003', [['text' => 'a', 'matchType' => 'PHRASE'], ['text' => 'b', 'matchType' => 'EXACT']])->status)->toBe('unknown');
    $h->queue[] = ['status' => 401, 'json' => ['error' => ['code' => 401]]];
    expect($call()->status)->toBe('token');
    $h->queue[] = ['status' => 400, 'json' => ['error' => ['status' => 'UNAUTHENTICATED']]];
    expect($call()->status)->toBe('token');
    $h->queue[] = ['status' => 403, 'json' => ['error' => ['code' => 403]]];
    expect(str_contains($call()->message, 'Developer Token'))->toBeTrue();
    $h->queue[] = ['status' => 400, 'json' => ['error' => ['status' => 'PERMISSION_DENIED']]];
    expect(str_contains($call()->message, 'Developer Token'))->toBeTrue();
    $h->queue[] = ['status' => 400, 'json' => ['results' => [['resourceName' => 'customers/1234567890/campaignBudgets/5000001']]]];
    expect($call()->status)->toBe('unknown');
    $h->queue[] = ['status' => 400, 'json' => ['error' => ['message' => str_repeat('x', 400)]]];
    expect(mb_strlen($call()->message))->toBeLessThan(280);
    $before = count($h->calls);
    expect($api->budget('12ab', null, 'A', [])->status)->toBe('rejected')->and($api->budget(GLC_CUST, 'x', 'A', [])->status)->toBe('rejected')->and(count($h->calls))->toBe($before);
});

it('creates every step in order, saving each id at once, all through the paused campaign', function () {
    [$l, $h, $repo] = glcmake();
    glcall($h);
    expect($l->launch(glcrow(), GLC_NOW))->toBe('created')->and($repo->names())->toBe(['step', 'step', 'step', 'step', 'step', 'step', 'done']);
    expect(array_column(array_column($repo->calls, 1), 2))->toContain('budget', 'campaign', 'adgroup', 'ad', 'keywords', 'targeting');
    expect(array_column(array_column(array_slice($repo->calls, 0, 6), 1), 3))->toBe(['5000001', '5000002', '5000003', '5000004', 'done', 'done']);
    expect($h->calls[0]['url'])->toBe('https://oauth2.googleapis.com/token');
    $urls = array_map(fn ($c) => substr($c['url'], strrpos($c['url'], '/') + 1), array_slice($h->calls, 1));
    expect($urls)->toBe(['campaignBudgets:mutate', 'campaigns:mutate', 'adGroups:mutate', 'adGroupAds:mutate', 'adGroupCriteria:mutate', 'campaignCriteria:mutate']);
    expect(json_decode($h->calls[2]['body'], true)['operations'][0]['create']['status'])->toBe('PAUSED');
    expect(json_decode($h->calls[4]['body'], true)['operations'][0]['create']['status'])->toBe('ENABLED');
    expect(json_decode($h->calls[3]['body'], true)['operations'][0]['create']['campaign'])->toBe('customers/'.GLC_CUST.'/campaigns/5000002');
    foreach (array_slice($h->calls, 1) as $c) {
        expect($c['headers']['Authorization'])->toBe('Bearer ya29.access-token-xyz')->and(str_contains($c['body'], GLC_TOKEN))->toBeFalse();
    }
});

it('resumes: finished steps are not created again', function () {
    [$l, $h, $repo] = glcmake();
    array_push($h->queue, glcaccess(), glcres('adGroupAds', '5000004'), glcmany(2), glcmany(3));
    $row = glcrow(['remote_budget' => '5000001', 'remote_campaign' => '5000002', 'remote_adgroup' => '5000003']);
    expect($l->launch($row, GLC_NOW))->toBe('created')->and(count($h->calls))->toBe(4)->and($h->calls[1]['url'])->toEndWith('/adGroupAds:mutate');
    expect(json_decode($h->calls[1]['body'], true)['operations'][0]['create']['adGroup'])->toBe('customers/'.GLC_CUST.'/adGroups/5000003');
    [$l, $h, $repo] = glcmake();
    array_push($h->queue, glcaccess(), glcmany(2));
    expect($l->launch(glcrow(['remote_budget' => '5000001', 'remote_campaign' => '5000002', 'remote_adgroup' => '5000003', 'remote_ad' => '5000004', 'targeting_done' => true]), GLC_NOW))->toBe('created')->and(count($h->calls))->toBe(2)->and($h->calls[1]['url'])->toEndWith('/adGroupCriteria:mutate');
    [$l, $h, $repo] = glcmake();
    array_push($h->queue, glcaccess(), glcmany(3));
    expect($l->launch(glcrow(['remote_budget' => '5000001', 'remote_campaign' => '5000002', 'remote_adgroup' => '5000003', 'remote_ad' => '5000004', 'keywords_done' => true]), GLC_NOW))->toBe('created')->and(count($h->calls))->toBe(2)->and($h->calls[1]['url'])->toEndWith('/campaignCriteria:mutate');
});

it('a rejection mid-way keeps earlier ids, is not unknown, and stops', function () {
    [$l, $h, $repo] = glcmake();
    array_push($h->queue, glcaccess(), glcres('campaignBudgets', '5000001'), ['status' => 400, 'json' => ['error' => ['code' => 400, 'details' => [['errors' => [['message' => 'bad date']]]]]]]);
    expect($l->launch(glcrow(), GLC_NOW))->toBe('failed')->and(count($h->calls))->toBe(3)->and($repo->names())->toBe(['step', 'fail']);
    $f = $repo->calls[1][1];
    expect($f[3])->toBeFalse()->and(str_contains($f[2], 'bad date'))->toBeTrue()->and(str_contains($f[2], 'Budget'))->toBeTrue();
});

it('an unknown outcome is flagged unknown and nothing further is created', function () {
    [$l, $h, $repo] = glcmake();
    array_push($h->queue, glcaccess(), glcres('campaignBudgets', '5000001'), ['status' => 503, 'json' => []]);
    expect($l->launch(glcrow(), GLC_NOW))->toBe('failed')->and(count($h->calls))->toBe(3)->and($repo->calls[1][1][3])->toBeTrue();
});

it('a token error marks the account invalid and is not unknown', function () {
    [$l, $h, $repo, $acc] = glcmake();
    array_push($h->queue, glcaccess(), ['status' => 401, 'json' => ['error' => ['status' => 'UNAUTHENTICATED']]]);
    expect($l->launch(glcrow(), GLC_NOW))->toBe('failed')->and($acc->names())->toBe(['fail'])->and($acc->calls[0][1][3])->toBeTrue()->and($repo->calls[0][1][3])->toBeFalse();
});

it('a revoked refresh token, a failed refresh, a bad plan or an unopenable token make no create call', function () {
    [$l, $h, $repo, $acc] = glcmake();
    $h->queue[] = ['status' => 400, 'json' => ['error' => 'invalid_grant']];
    expect($l->launch(glcrow(), GLC_NOW))->toBe('failed')->and(count($h->calls))->toBe(1)->and($acc->names())->toBe(['fail'])->and($acc->calls[0][1][3])->toBeTrue()->and($repo->calls[0][1][3])->toBeFalse();
    [$l, $h, $repo, $acc] = glcmake();
    $h->queue[] = ['status' => 500, 'json' => []];
    expect($l->launch(glcrow(), GLC_NOW))->toBe('failed')->and(count($h->calls))->toBe(1)->and($acc->names())->toBe([])->and($repo->calls[0][1][3])->toBeFalse();
    [$l, $h, $repo, $acc] = glcmake();
    expect($l->launch(glcrow(['country' => 'FR']), GLC_NOW))->toBe('failed')->and(count($h->calls))->toBe(0)->and($repo->calls[0][1][3])->toBeFalse();
    [$l, $h, $repo, $acc] = glcmake();
    expect($l->launch(glcrow(['cipher' => 'sealed:other:xx']), GLC_NOW))->toBe('failed')->and(count($h->calls))->toBe(0)->and($acc->names())->toBe(['fail'])->and($repo->calls[0][1][3])->toBeFalse();
});

it('if saving an id fails the attempt stops as unknown with the id in the note; if done fails it is unknown too', function () {
    [$l, $h, $repo] = glcmake();
    $repo->stepAnswer = 'nope';
    array_push($h->queue, glcaccess(), glcres('campaignBudgets', '5000001'));
    expect($l->launch(glcrow(), GLC_NOW))->toBe('failed')->and(count($h->calls))->toBe(2);
    $f = end($repo->calls)[1];
    expect($f[3])->toBeTrue();
    [$l, $h, $repo] = glcmake();
    $repo->doneAnswer = 'nope';
    glcall($h);
    expect($l->launch(glcrow(), GLC_NOW))->toBe('failed')->and(end($repo->calls)[1][3])->toBeTrue();
});
