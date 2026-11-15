<?php

use App\Ads\AdsLauncher;
use App\Ads\AdsLaunchPlan;
use App\Ads\AdsMoney;
use App\Ads\MetaAdsLaunchApi;
use Tests\Support\FakeAdsLaunchRepo;
use Tests\Support\FakeAdsRepo;
use Tests\Support\FakeHttpJson;
use Tests\Support\FakeSealer;

const ADL_TOKEN = 'EAAB-launch-token_0123456789abcdef';
const ADL_NOW = 1800000000; // 2027-01-15 UTC

function adlrow(array $o = []): array
{
    return $o + ['id' => 'DRAFT000000000000000000AA', 'tenant_id' => 'TENANT0000000000000000000A', 'user_id' => 'USER0000000000000000000AAA', 'account_id' => 'ACC00000000000000000000AAA', 'account_ext' => '123456789', 'currency' => 'BDT',
        'pixel_id' => '987654321', 'cipher' => (new FakeSealer)->seal(ADL_TOKEN)['cipher'], 'version' => 1, 'page_ext' => '555000111', 'name' => 'ঈদ অফার', 'objective' => 'traffic', 'country' => 'BD', 'age_min' => 20, 'age_max' => 45, 'gender' => 'all',
        'primary_text' => 'নতুন কালেকশন', 'headline' => 'ঈদ অফার', 'description' => '', 'cta' => 'SHOP_NOW', 'link_url' => 'https://shop.example.com/eid', 'daily_budget' => '500.00', 'days' => 7, 'start_date' => '2027-01-20',
        'remote_campaign' => null, 'remote_adset' => null, 'remote_creative' => null, 'remote_ad' => null];
}

function adlmake(): array
{
    $h = new FakeHttpJson;
    $repo = new FakeAdsLaunchRepo;
    $acc = new FakeAdsRepo;

    return [new AdsLauncher($repo, $acc, new FakeSealer, new MetaAdsLaunchApi($h, 'v24.0')), $h, $repo, $acc];
}

function adlok(string $id): array
{
    return ['status' => 200, 'json' => ['id' => $id]];
}

it('converts money only for verified 1/100 currencies', function () {
    expect(AdsMoney::minor('500.00', 'BDT'))->toBe(50000)->and(AdsMoney::minor('5', 'USD'))->toBe(500)->and(AdsMoney::minor('12.5', 'EUR'))->toBe(1250)
        ->and(AdsMoney::minor('500', 'JPY'))->toBeNull()->and(AdsMoney::minor('500', 'KWD'))->toBeNull()->and(AdsMoney::minor('0', 'BDT'))->toBeNull()
        ->and(AdsMoney::minor('0.00', 'BDT'))->toBeNull()->and(AdsMoney::minor('-5', 'BDT'))->toBeNull()->and(AdsMoney::minor('1.234', 'BDT'))->toBeNull()->and(AdsMoney::minor('abc', 'BDT'))->toBeNull();
});

it('plans a traffic ad with everything paused and an end date that keeps the approved total', function () {
    $p = AdsLaunchPlan::build(adlrow(), ADL_NOW);
    expect($p['error'])->toBeNull()->and($p['campaign']['objective'])->toBe('OUTCOME_TRAFFIC')->and($p['campaign']['status'])->toBe('PAUSED')->and($p['adset']['status'])->toBe('PAUSED')->and($p['ad']['status'])->toBe('PAUSED')
        ->and($p['adset']['daily_budget'])->toBe('50000')->and($p['adset']['optimization_goal'])->toBe('LINK_CLICKS')->and($p['adset']['start_time'])->toBe('2027-01-20T00:00:00+0000')->and($p['adset']['end_time'])->toBe('2027-01-27T00:00:00+0000');
    expect(str_contains(json_encode($p), 'ACTIVE'))->toBeFalse();
    expect($p['adset']['bid_strategy'])->toBe('LOWEST_COST_WITHOUT_CAP')->and($p['adset']['billing_event'])->toBe('IMPRESSIONS')->and($p['campaign']['is_adset_budget_sharing_enabled'])->toBe('false')->and($p['campaign']['special_ad_categories'])->toBe('[]');
    $t = json_decode($p['adset']['targeting'], true);
    expect($t['geo_locations']['countries'])->toBe(['BD'])->and($t['age_min'])->toBe(20)->and(isset($t['genders']))->toBeFalse();
    $s = json_decode($p['creative']['object_story_spec'], true);
    expect($s['page_id'])->toBe('555000111')->and($s['link_data']['link'])->toBe('https://shop.example.com/eid')->and($s['link_data']['call_to_action']['type'])->toBe('SHOP_NOW')->and(isset($s['link_data']['description']))->toBeFalse();
});

it('starts today when the chosen date has passed, and maps gender and description', function () {
    $p = AdsLaunchPlan::build(adlrow(['start_date' => '2020-01-01', 'gender' => 'women', 'description' => 'ফ্রি ডেলিভারি', 'days' => 3]), ADL_NOW);
    expect($p['adset']['start_time'])->toBe('2027-01-15T00:00:00+0000')->and($p['adset']['end_time'])->toBe('2027-01-18T00:00:00+0000')->and(json_decode($p['adset']['targeting'], true)['genders'])->toBe([2]);
    expect(json_decode($p['creative']['object_story_spec'], true)['link_data']['description'])->toBe('ফ্রি ডেলিভারি');
    expect(json_decode(AdsLaunchPlan::build(adlrow(['gender' => 'men']), ADL_NOW)['adset']['targeting'], true)['genders'])->toBe([1]);
});

it('plans awareness, and sales only with a pixel', function () {
    $a = AdsLaunchPlan::build(adlrow(['objective' => 'awareness']), ADL_NOW);
    expect($a['campaign']['objective'])->toBe('OUTCOME_AWARENESS')->and($a['adset']['optimization_goal'])->toBe('REACH')->and(isset($a['adset']['promoted_object']))->toBeFalse();
    $s = AdsLaunchPlan::build(adlrow(['objective' => 'sales']), ADL_NOW);
    expect($s['error'])->toBeNull()->and($s['campaign']['objective'])->toBe('OUTCOME_SALES')->and($s['adset']['optimization_goal'])->toBe('OFFSITE_CONVERSIONS')->and(json_decode($s['adset']['promoted_object'], true))->toBe(['pixel_id' => '987654321', 'custom_event_type' => 'PURCHASE']);
    expect(is_string(AdsLaunchPlan::build(adlrow(['objective' => 'sales', 'pixel_id' => null]), ADL_NOW)['error']))->toBeTrue();
});

it('refuses what it cannot create correctly', function () {
    foreach ([['objective' => 'leads'], ['objective' => 'messages'], ['cta' => 'SEND_MESSAGE'], ['link_url' => 'http://x.example.com'], ['link_url' => ''], ['currency' => 'JPY'], ['start_date' => null], ['daily_budget' => '0.00']] as $o) {
        $p = AdsLaunchPlan::build(adlrow($o), ADL_NOW);
        expect(is_string($p['error']))->toBeTrue()->and($p['campaign'])->toBe([]);
    }
});

it('forces PAUSED on campaign, ad set and ad even if the params say otherwise, and keeps the token in the header', function () {
    $h = new FakeHttpJson;
    $api = new MetaAdsLaunchApi($h, 'v24.0');
    foreach (['campaign', 'adSet', 'ad'] as $m) {
        $h->queue[] = adlok('111222333');
        $r = $api->$m('123456789', ADL_TOKEN, ['name' => 'x', 'status' => 'ACTIVE']);
        expect($r->status)->toBe('ok')->and($r->id)->toBe('111222333');
    }
    $h->queue[] = adlok('111222334');
    $api->creative('123456789', ADL_TOKEN, ['name' => 'c']);
    expect($h->calls[0]['form']['status'])->toBe('PAUSED')->and($h->calls[1]['form']['status'])->toBe('PAUSED')->and($h->calls[2]['form']['status'])->toBe('PAUSED');
    expect($h->calls[0]['url'])->toBe('https://graph.facebook.com/v24.0/act_123456789/campaigns')->and($h->calls[1]['url'])->toEndWith('/act_123456789/adsets')->and($h->calls[2]['url'])->toEndWith('/act_123456789/ads')->and($h->calls[3]['url'])->toEndWith('/act_123456789/adcreatives');
    foreach ($h->calls as $c) {
        expect($c['method'])->toBe('POST')->and($c['headers']['Authorization'])->toBe('Bearer '.ADL_TOKEN)->and(str_contains($c['url'], 'EAAB'))->toBeFalse()->and(str_contains(json_encode($c['form']), 'EAAB'))->toBeFalse();
    }
});

it('classifies Meta answers: ok, token, rejected, unknown', function () {
    $h = new FakeHttpJson;
    $api = new MetaAdsLaunchApi($h, 'v24.0');
    $h->queue[] = ['status' => 400, 'json' => ['error' => ['code' => 190, 'message' => 'expired']]];
    expect($api->campaign('123456789', ADL_TOKEN, [])->status)->toBe('token');
    $h->queue[] = ['status' => 400, 'json' => ['error' => ['code' => 100, 'message' => 'Invalid parameter', 'error_user_msg' => "Bad\nbudget"]]];
    $r = $api->campaign('123456789', ADL_TOKEN, []);
    expect($r->status)->toBe('rejected')->and(str_contains($r->message, 'Bad budget'))->toBeTrue();
    $h->queue[] = ['status' => 500, 'json' => []];
    expect($api->campaign('123456789', ADL_TOKEN, [])->status)->toBe('unknown');
    $h->queue[] = ['status' => 400, 'json' => ['error' => ['code' => 2, 'message' => 'temporary']]];
    expect($api->campaign('123456789', ADL_TOKEN, [])->status)->toBe('unknown');
    $h->queue[] = ['status' => 502, 'json' => ['error' => ['code' => 100, 'message' => 'gateway']]];
    expect($api->campaign('123456789', ADL_TOKEN, [])->status)->toBe('unknown');
    $h->queue[] = ['status' => 400, 'json' => []];
    expect($api->campaign('123456789', ADL_TOKEN, [])->status)->toBe('unknown');
    $h->queue[] = ['status' => 200, 'json' => ['id' => 'abc']];
    expect($api->campaign('123456789', ADL_TOKEN, [])->status)->toBe('unknown');
    $h->queue[] = ['status' => 200, 'json' => []];
    expect($api->campaign('123456789', ADL_TOKEN, [])->status)->toBe('unknown');
    $before = count($h->calls);
    expect($api->campaign('12ab', ADL_TOKEN, [])->status)->toBe('rejected')->and(count($h->calls))->toBe($before);
});

it('creates the four objects in order, saving each id at once', function () {
    [$l, $h, $repo] = adlmake();
    array_push($h->queue, adlok('1000001'), adlok('1000002'), adlok('1000003'), adlok('1000004'));
    expect($l->launch(adlrow(), ADL_NOW))->toBe('created')->and($repo->names())->toBe(['step', 'step', 'step', 'step', 'done']);
    expect(array_column(array_column($repo->calls, 1), 2))->toContain('campaign', 'adset', 'creative', 'ad');
    expect($h->calls[1]['form']['campaign_id'])->toBe('1000001')->and($h->calls[3]['form']['adset_id'])->toBe('1000002')->and($h->calls[3]['form']['creative'])->toBe('{"creative_id":"1000003"}');
    foreach ([0, 1, 3] as $i) {
        expect($h->calls[$i]['form']['status'])->toBe('PAUSED');
    }
});

it('resumes: a step that already has an id is not created again', function () {
    [$l, $h, $repo] = adlmake();
    array_push($h->queue, adlok('1000003'), adlok('1000004'));
    expect($l->launch(adlrow(['remote_campaign' => '1000001', 'remote_adset' => '1000002']), ADL_NOW))->toBe('created')->and(count($h->calls))->toBe(2)->and($h->calls[0]['url'])->toEndWith('/adcreatives')->and($h->calls[1]['form']['adset_id'])->toBe('1000002');
});

it('a rejection mid-way keeps earlier ids, is not unknown, and stops', function () {
    [$l, $h, $repo] = adlmake();
    array_push($h->queue, adlok('1000001'), ['status' => 400, 'json' => ['error' => ['code' => 100, 'message' => 'bad targeting']]]);
    expect($l->launch(adlrow(), ADL_NOW))->toBe('failed')->and(count($h->calls))->toBe(2)->and($repo->names())->toBe(['step', 'fail']);
    $f = $repo->calls[1][1];
    expect($f[3])->toBeFalse()->and(str_contains($f[2], 'bad targeting'))->toBeTrue();
});

it('an unknown outcome is flagged unknown and nothing further is created', function () {
    [$l, $h, $repo] = adlmake();
    array_push($h->queue, adlok('1000001'), ['status' => 503, 'json' => []]);
    expect($l->launch(adlrow(), ADL_NOW))->toBe('failed')->and(count($h->calls))->toBe(2)->and($repo->calls[1][1][3])->toBeTrue();
});

it('a token error marks the account invalid and is not unknown', function () {
    [$l, $h, $repo, $acc] = adlmake();
    $h->queue[] = ['status' => 400, 'json' => ['error' => ['code' => 190, 'message' => 'bad']]];
    expect($l->launch(adlrow(), ADL_NOW))->toBe('failed')->and($acc->names())->toBe(['fail'])->and($acc->calls[0][1][3])->toBeTrue()->and($repo->calls[0][1][3])->toBeFalse();
});

it('a bad plan or an unopenable token makes no Meta call', function () {
    [$l, $h, $repo, $acc] = adlmake();
    expect($l->launch(adlrow(['objective' => 'leads']), ADL_NOW))->toBe('failed')->and(count($h->calls))->toBe(0)->and($repo->calls[0][1][3])->toBeFalse();
    [$l, $h, $repo, $acc] = adlmake();
    expect($l->launch(adlrow(['cipher' => 'sealed:other:xx']), ADL_NOW))->toBe('failed')->and(count($h->calls))->toBe(0)->and($acc->names())->toBe(['fail'])->and($repo->calls[0][1][3])->toBeFalse();
});

it('if saving an id fails the attempt stops as unknown with the id in the note; if done fails it is unknown too', function () {
    [$l, $h, $repo] = adlmake();
    $repo->stepAnswer = 'nope';
    $h->queue[] = adlok('1000001');
    expect($l->launch(adlrow(), ADL_NOW))->toBe('failed')->and(count($h->calls))->toBe(1);
    $f = end($repo->calls)[1];
    expect($f[3])->toBeTrue()->and(str_contains($f[2], '1000001'))->toBeTrue();
    [$l, $h, $repo] = adlmake();
    $repo->doneAnswer = 'nope';
    array_push($h->queue, adlok('1000001'), adlok('1000002'), adlok('1000003'), adlok('1000004'));
    expect($l->launch(adlrow(), ADL_NOW))->toBe('failed')->and(end($repo->calls)[1][3])->toBeTrue();
});
