<?php

use App\Leads\DomainNormalizer;
use App\Leads\LeadIngestor;
use App\Leads\ResearchRunner;
use App\Leads\UrlList;
use Tests\Support\FakeLeadEngine;
use Tests\Support\FakeLeadStore;

const T1 = 'TENANT1000000000000000000A';
const T2 = 'TENANT2000000000000000000B';

function runner(): array
{
    $s = new FakeLeadStore;
    $e = new FakeLeadEngine;

    return [new ResearchRunner($s, $e, new LeadIngestor($s)), $s, $e];
}

it('normalises domains and rejects the unsafe or silly', function () {
    expect(DomainNormalizer::fromInput('https://WWW.Alo-Digital.com.bd/about?x=1'))->toBe('alo-digital.com.bd');
    expect(DomainNormalizer::fromInput('alo.com'))->toBe('alo.com');
    expect(DomainNormalizer::fromInput('http://shop.alo.com:8080/'))->toBe('shop.alo.com');
    foreach (['', 'localhost', '127.0.0.1', 'http://169.254.169.254/', 'http://[::1]/', 'ftp://alo.com', 'file:///etc/passwd', 'javascript:alert(1)', 'a b.com', 'alo', 'x.local', 'http://u:p@alo.com', "alo.com\nevil.com", '1.2.3.4', 'alo.123', str_repeat('a', 70).'.com'] as $bad) {
        expect(DomainNormalizer::fromInput($bad))->toBeNull();
    }
});

it('parses pasted text: dedupes by domain, rejects junk, caps the size', function () {
    $r = UrlList::fromText("https://alo.com, www.alo.com/contact\nbeta.com.bd;gamma.org not a url 10.0.0.1", 3);
    expect(array_column($r['items'], 'domain'))->toBe(['alo.com', 'beta.com.bd', 'gamma.org'])->and($r['duplicates'])->toBe(1)->and(count($r['rejected']))->toBe(4);
    $big = UrlList::fromText(implode(' ', array_map(fn ($i) => "site$i.com", range(1, 10))), 4);
    expect($big['items'])->toHaveCount(4)->and($big['truncated'])->toBeTrue();
});

it('parses CSV with header, BOM, semicolons and no header', function () {
    $a = UrlList::fromCsv("\xEF\xBB\xBFName,Website,Phone\nAlo,https://alo.com,017\nBeta,beta.com.bd,018\n", 10);
    expect(array_column($a['items'], 'domain'))->toBe(['alo.com', 'beta.com.bd']);
    $b = UrlList::fromCsv("name;url\nAlo;alo.com\nAlo2;www.alo.com\n", 10);
    expect($b['items'])->toHaveCount(1)->and($b['duplicates'])->toBe(1);
    $c = UrlList::fromCsv("alo.com\nbeta.com.bd\n", 10);
    expect($c['items'])->toHaveCount(2);
    $d = UrlList::fromCsv("Company\nAlo\n", 10);
    expect($d['items'])->toHaveCount(0);
    expect(UrlList::fromCsv('', 10)['items'])->toHaveCount(0);
});

it('a crawl result becomes company, lead and contacts', function () {
    [$r, $s, $e] = runner();
    $e->results['alo.com'] = FakeLeadEngine::okResult('alo.com');
    $job = $s->createJob(T1, 'USER1', 'j', 'url', [['url' => 'https://alo.com/', 'domain' => 'alo.com']], ['max_pages' => 3]);
    expect($r->process($s->itemIds($job)[0]))->toBe('done');
    expect($s->companies)->toHaveCount(1)->and($s->leads)->toHaveCount(1)->and($s->contacts)->toHaveCount(2)->and($s->jobs[$job]['status'])->toBe('done')
        ->and($e->calls[0]['maxPages'])->toBe(3)->and($e->calls[0]['tenantId'])->toBe(T1);
    $lead = array_values($s->leads)[0];
    expect($lead['score'])->toBe(70)->and($lead['stage'])->toBe('new');
});

it('crawling the same site again updates, never duplicates', function () {
    [$r, $s, $e] = runner();
    $e->results['alo.com'] = FakeLeadEngine::okResult('alo.com');
    foreach ([1, 2] as $n) {
        $job = $s->createJob(T1, 'USER1', "j$n", 'url', [['url' => 'https://alo.com/', 'domain' => 'alo.com']], []);
        $e->results['alo.com'] = FakeLeadEngine::okResult('alo.com', ['score' => 60 + $n]);
        $r->process($s->itemIds($job)[0]);
    }
    expect($s->companies)->toHaveCount(1)->and($s->leads)->toHaveCount(1)->and($s->contacts)->toHaveCount(2)->and(array_values($s->leads)[0]['score'])->toBe(62);
});

it('an email already known to the tenant is not stored twice, but another tenant may have it', function () {
    [$r, $s, $e] = runner();
    $e->results['a.com'] = FakeLeadEngine::okResult('a.com', ['emails' => [['email' => 'shared@x.com', 'status' => 'mx_ok']], 'phones' => []]);
    $e->results['b.com'] = FakeLeadEngine::okResult('b.com', ['emails' => [['email' => 'shared@x.com', 'status' => 'mx_ok']], 'phones' => []]);
    foreach ([['a.com', T1], ['b.com', T1], ['b.com', T2]] as [$d, $t]) {
        $j = $s->createJob($t, 'U', 'j', 'url', [['url' => "https://$d/", 'domain' => $d]], []);
        $r->process($s->itemIds($j)[0]);
    }
    expect($s->contacts)->toHaveCount(2)->and(array_column($s->contacts, 'tenant'))->toBe([T1, T2]);
});

it('hostile engine output cannot break the save or smuggle bad values', function () {
    [$r, $s, $e] = runner();
    $e->results['alo.com'] = FakeLeadEngine::okResult('alo.com', [
        'score' => 9999, 'company' => ['name' => str_repeat('অ', 500)], 'emails' => ['junk', ['email' => 'not-an-email'], ['email' => 'A@B.com', 'status' => 'evil'], ['x' => 1]],
        'phones' => [['e164' => '01712345678'], ['e164' => '+8801812345678', 'type' => 'mobile'], 'str'], 'gaps' => ['str', ['code' => 'a']],
    ]);
    $j = $s->createJob(T1, 'U', 'j', 'url', [['url' => 'https://alo.com/', 'domain' => 'alo.com']], []);
    expect($r->process($s->itemIds($j)[0]))->toBe('done');
    $c = array_values($s->companies)[0];
    expect($c['score'])->toBe(100)->and(mb_strlen($c['name']))->toBe(200);
    expect(array_column($s->contacts, 'email'))->toContain('a@b.com');
    expect(array_values(array_filter(array_column($s->contacts, 'email_status')))[0])->toBe('syntax_ok');
    expect(count(array_filter(array_column($s->contacts, 'phone'))))->toBe(1);
});

it('a result without a valid domain fails the item, not the worker', function () {
    [$r, $s, $e] = runner();
    $e->results['alo.com'] = FakeLeadEngine::okResult('alo.com', ['domain' => '127.0.0.1']);
    $j = $s->createJob(T1, 'U', 'j', 'url', [['url' => 'https://alo.com/', 'domain' => 'alo.com']], []);
    expect($r->process($s->itemIds($j)[0]))->toBe('failed')->and($s->companies)->toHaveCount(0)->and($s->jobs[$j]['status'])->toBe('done');
});

it('engine statuses map to item outcomes', function () {
    [$r, $s, $e] = runner();
    $e->results = ['a.com' => ['status' => 'blocked_by_robots'], 'b.com' => ['status' => 'unreachable'], 'c.com' => ['status' => 'unsafe_url'], 'd.com' => ['status' => 'weird']];
    $j = $s->createJob(T1, 'U', 'j', 'url', array_map(fn ($d) => ['url' => "https://$d/", 'domain' => $d], array_keys($e->results)), []);
    $out = array_map(fn ($id) => $r->process($id), $s->itemIds($j));
    expect($out)->toBe(['skipped', 'failed', 'failed', 'failed'])->and($s->jobs[$j]['status'])->toBe('done')->and($s->companies)->toHaveCount(0);
    expect(array_column($s->items, 'code'))->toBe(['blocked_by_robots', 'unreachable', 'unsafe_url', 'engine_error']);
});

it('an unavailable engine retries, then fails on the last attempt; one bad site does not stop the others', function () {
    [$r, $s, $e] = runner();
    $e->results = ['down.com' => new RuntimeException('engine unreachable'), 'ok.com' => FakeLeadEngine::okResult('ok.com')];
    $j = $s->createJob(T1, 'U', 'j', 'url', [['url' => 'https://down.com/', 'domain' => 'down.com'], ['url' => 'https://ok.com/', 'domain' => 'ok.com']], []);
    [$down, $ok] = $s->itemIds($j);
    expect($r->process($down))->toBe('retry')->and($s->items[$down]['status'])->toBe('queued')->and($r->process($ok))->toBe('done')->and($s->jobs[$j]['status'])->toBe('running');
    expect($r->process($down, true))->toBe('failed')->and($s->jobs[$j]['status'])->toBe('done')->and($s->items[$down]['code'])->toBe('engine_error');
});

it('an item is processed once; a cancelled job processes nothing', function () {
    [$r, $s, $e] = runner();
    $e->results['alo.com'] = FakeLeadEngine::okResult('alo.com');
    $j = $s->createJob(T1, 'U', 'j', 'url', [['url' => 'https://alo.com/', 'domain' => 'alo.com']], []);
    $id = $s->itemIds($j)[0];
    $r->process($id);
    expect($r->process($id))->toBe('skipped')->and($e->calls)->toHaveCount(1);
    $j2 = $s->createJob(T1, 'U', 'j2', 'url', [['url' => 'https://b.com/', 'domain' => 'b.com']], []);
    $s->jobs[$j2]['status'] = 'cancelled';
    expect($r->process($s->itemIds($j2)[0]))->toBe('skipped')->and($e->calls)->toHaveCount(1);
});

it('contacts per company are capped', function () {
    [$r, $s, $e] = runner();
    $emails = array_map(fn ($i) => ['email' => "p$i@alo.com", 'status' => 'mx_ok'], range(1, 30));
    $e->results['alo.com'] = FakeLeadEngine::okResult('alo.com', ['emails' => $emails, 'phones' => []]);
    $j = $s->createJob(T1, 'U', 'j', 'url', [['url' => 'https://alo.com/', 'domain' => 'alo.com']], []);
    $r->process($s->itemIds($j)[0]);
    expect($s->contacts)->toHaveCount(LeadIngestor::MAX_CONTACTS_PER_COMPANY);
});

it('a re-crawl never overwrites a company name the user may have edited', function () {
    [$r, $s, $e] = runner();
    $e->results['alo.com'] = FakeLeadEngine::okResult('alo.com', ['company' => ['name' => 'First']]);
    $j = $s->createJob(T1, 'U', 'j', 'url', [['url' => 'https://alo.com/', 'domain' => 'alo.com']], []);
    $r->process($s->itemIds($j)[0]);
    $id = array_key_first($s->companies);
    $s->companies[$id]['data']['name'] = 'Edited by user';
    $e->results['alo.com'] = FakeLeadEngine::okResult('alo.com', ['company' => ['name' => 'Second']]);
    $j2 = $s->createJob(T1, 'U', 'j2', 'url', [['url' => 'https://alo.com/', 'domain' => 'alo.com']], []);
    $r->process($s->itemIds($j2)[0]);
    expect($s->companies[$id]['data']['name'])->toBe('Edited by user');
});
