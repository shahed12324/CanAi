<?php

use App\Seo\SeoService;
use App\Seo\SeoText;
use Tests\Support\FakeSeoEngine;
use Tests\Support\FakeSeoRepo;

function seoRig(): array
{
    $e = new FakeSeoEngine;
    $r = new FakeSeoRepo;

    return [new SeoService($e, $r), $e, $r];
}

it('turns what the owner typed into a safe start URL, or refuses it', function () {
    expect(SeoText::url('example.com'))->toBe('https://example.com/');
    expect(SeoText::url('  WWW.Example.COM/path?q=1#x '))->toBe('https://www.example.com/');
    expect(SeoText::url('http://example.com/a'))->toBe('http://example.com/');
    expect(SeoText::url('https://sub.shop.com.bd'))->toBe('https://sub.shop.com.bd/');
    foreach (['', '   ', 'localhost', '127.0.0.1', 'http://10.0.0.5/', '[::1]', 'ftp://example.com', 'javascript:alert(1)', 'http://user:pw@example.com/', 'example.com:8080', 'intranet.local', 'a b.com', "exa\nmple.com", 'no-tld', 'x.test', str_repeat('a', 2050).'.com'] as $bad) {
        expect(SeoText::url($bad) === null)->toBeTrue();
    }
});

it('cleans the keyword: collapses spaces and control characters, caps at 80, empty means none', function () {
    expect(SeoText::keyword("  Dhaka \t এসইও\n"))->toBe('Dhaka এসইও');
    expect(SeoText::keyword(null))->toBeNull();
    expect(SeoText::keyword("  \n "))->toBeNull();
    expect(mb_strlen((string) SeoText::keyword(str_repeat('ক', 200))))->toBe(80);
});

it('keeps only what it renders from an engine result, with bounded sizes and a safe severity', function () {
    $raw = (new FakeSeoEngine)->result;
    $raw['score'] = 250;
    $raw['evil'] = '<script>';
    $raw['issues'] = array_merge($raw['issues'], [['code' => 'x', 'severity' => 'weird', 'label' => str_repeat('অ', 500), 'advice' => 'a', 'count' => -3, 'pages' => array_fill(0, 9, 'https://a.test')], 'junk', ['nocode' => 1]]);
    $c = SeoText::clean($raw);
    expect($c['score'])->toBe(100);
    expect(array_key_exists('evil', $c))->toBeFalse();
    expect($c['issues'])->toHaveCount(2);
    expect($c['issues'][1]['severity'])->toBe('low');
    expect(mb_strlen($c['issues'][1]['label']))->toBe(200);
    expect($c['issues'][1]['count'])->toBe(0);
    expect($c['issues'][1]['pages'])->toHaveCount(5);
    expect(SeoText::clean(['status' => 'nonsense'])['status'])->toBe('unreachable');
    expect(SeoText::clean(['status' => 'blocked_by_robots', 'url' => 'https://a.test/', 'junk' => 1]))->toBe(['status' => 'blocked_by_robots', 'url' => 'https://a.test/', 'final_url' => '', 'domain' => '']);
});

it('bounds the page and issue lists', function () {
    $raw = (new FakeSeoEngine)->result;
    $raw['issues'] = array_fill(0, 60, ['code' => 'c', 'severity' => 'high', 'label' => 'l', 'advice' => 'a', 'count' => 1, 'pages' => []]);
    $raw['pages'] = array_fill(0, 20, ['url' => 'https://a.test', 'status' => 200, 'title' => 't', 'words' => 1, 'issues' => []]);
    $c = SeoText::clean($raw);
    expect($c['issues'])->toHaveCount(40);
    expect($c['pages'])->toHaveCount(8);
});

it('grades the score and names the severities in Bengali', function () {
    expect(SeoText::grade(85))->toBe('Good');
    expect(SeoText::grade(84))->toBe('Fair');
    expect(SeoText::grade(60))->toBe('Fair');
    expect(SeoText::grade(59))->toBe('Needs improvement');
    expect(SeoText::severity('high'))->toBe('Urgent');
    expect(SeoText::severity('nope'))->toBe('Small');
});

it('every outcome and engine status has its own Bengali message', function () {
    $all = ['ok', 'bad_url', 'limit', 'client', 'too_large', 'engine_down', 'missing', 'error', 'unsafe_url', 'blocked_by_robots', 'unreachable', 'not_html', 'timeout'];
    foreach (SeoText::STATUSES as $st) {
        expect(in_array($st, $all, true))->toBeTrue();
    }
    $seen = [];
    foreach ($all as $code) {
        $m = SeoText::message($code);
        expect(preg_match('/[\x{0980}-\x{09FF}]/u', $m))->toBe(1);
        $seen[$m] = true;
    }
    expect(count($seen))->toBe(count($all));
});

it('runs an audit: normalised URL and keyword go to the engine, the cleaned result is stored with its score', function () {
    [$svc, $e, $r] = seoRig();
    $out = $svc->run('T1', 'U1', ' Alo.com/x ', 3, '  এসইও ', str_repeat('c', 26));
    expect($out['outcome'])->toBe('ok');
    expect($out['status'])->toBe('ok');
    expect(strlen((string) $out['id']))->toBe(26);
    expect($e->calls)->toBe([['T1', 'U1', 'https://alo.com/', 3, 'এসইও']]);
    expect($r->saved)->toHaveCount(1);
    expect($r->saved[0]['score'])->toBe(77);
    expect($r->saved[0]['domain'])->toBe('alo.com');
    expect($r->saved[0]['pages'])->toBe(2);
    expect($r->saved[0]['clientId'])->toBe(str_repeat('c', 26));
    expect($r->saved[0]['id'])->toBe($out['id']);
    expect($r->saved[0]['keyword'])->toBe('এসইও');
});

it('clamps the page count to 1..8', function () {
    [$svc, $e] = seoRig();
    $svc->run('T', 'U', 'alo.com', 0, null, null);
    $svc->run('T', 'U', 'alo.com', 99, null, null);
    expect([$e->calls[0][3], $e->calls[1][3]])->toBe([1, 8]);
});

it('refuses a bad address without touching the engine or the database', function () {
    [$svc, $e, $r] = seoRig();
    expect($svc->run('T', 'U', 'localhost', 5, null, null))->toBe(['outcome' => 'bad_url', 'id' => null, 'status' => null]);
    expect($e->calls)->toBe([]);
    expect($r->allowedChecks)->toBe(0);
    expect($r->saved)->toBe([]);
});

it('refuses BEFORE crawling anything when the daily limit is used up', function () {
    [$svc, $e, $r] = seoRig();
    $r->allowed = false;
    expect($svc->run('T', 'U', 'alo.com', 5, null, null))->toBe(['outcome' => 'limit', 'id' => null, 'status' => null]);
    expect($e->calls)->toBe([]);
    expect($r->saved)->toBe([]);
});

it('saves nothing and reports engine_down when the engine cannot be reached', function () {
    [$svc, $e, $r] = seoRig();
    $e->down = true;
    expect($svc->run('T', 'U', 'alo.com', 5, null, null)['outcome'])->toBe('engine_down');
    expect($r->saved)->toBe([]);
});

it('stores a failed crawl (blocked, unreachable...) without a score, so the history shows it', function () {
    [$svc, $e, $r] = seoRig();
    $e->result = ['status' => 'blocked_by_robots', 'url' => 'https://alo.com/', 'domain' => 'alo.com'];
    $out = $svc->run('T', 'U', 'alo.com', 5, null, null);
    expect($out['outcome'])->toBe('ok');
    expect($out['status'])->toBe('blocked_by_robots');
    expect($r->saved[0]['score'])->toBeNull();
    expect($r->saved[0]['status'])->toBe('blocked_by_robots');
    expect($r->saved[0]['pages'])->toBe(0);
});

it('an unknown engine status is stored as unreachable, never as a scored audit', function () {
    [$svc, $e, $r] = seoRig();
    $e->result = ['status' => 'mystery', 'score' => 99];
    $svc->run('T', 'U', 'alo.com', 5, null, null);
    expect($r->saved[0]['status'])->toBe('unreachable');
    expect($r->saved[0]['score'])->toBeNull();
});

it('passes the database refusals through and survives a database exception', function () {
    foreach (['limit', 'client', 'too_large'] as $code) {
        [$svc, , $r] = seoRig();
        $r->saveReturns = $code;
        $out = $svc->run('T', 'U', 'alo.com', 5, null, null);
        expect($out['outcome'])->toBe($code);
        expect($out['id'])->toBeNull();
    }
    [$svc, , $r] = seoRig();
    $r->throwOnSave = true;
    expect($svc->run('T', 'U', 'alo.com', 5, null, null)['outcome'])->toBe('error');
});
