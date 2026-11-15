<?php

use App\Proposals\ProposalEngineUnavailable;
use App\Reports\ReportBuilder;
use App\Reports\ReportPeriod;
use App\Reports\ReportService;
use Tests\Support\FakeProposalEngine;
use Tests\Support\FakeReportRepo;

const RPT_NOW = '2027-01-15 10:00:00';

function rptnow(): DateTimeImmutable
{
    return new DateTimeImmutable(RPT_NOW, new DateTimeZone('Asia/Dhaka'));
}

function rptrow(string $acc, string $cur, string $name, string $spend, int $imp, int $clicks, string $res, ?string $synced = '2027-01-15 09:00:00', string $accName = 'আমার অ্যাকাউন্ট'): array
{
    return ['account_id' => $acc, 'account' => $accName, 'currency' => $cur, 'synced_at' => $synced, 'campaign' => '100'.crc32($name), 'name' => $name, 'spend' => $spend, 'impressions' => $imp, 'clicks' => $clicks, 'results' => $res];
}

function rptin(array $o = []): array
{
    $now = rptnow();

    return $o + ['language' => 'en', 'kind' => 'week', 'range' => ReportPeriod::range('week', $now), 'today' => $now, 'client_name' => 'Acme', 'notes' => '', 'brand' => null, 'brand_fallback_name' => 'My Agency',
        'ads' => ['meta' => [], 'google' => []], 'prev' => [], 'social' => null];
}

function rpttext(array $doc): string
{
    return json_encode($doc, JSON_UNESCAPED_UNICODE);
}

it('periods end yesterday; a week has a previous week, a month does not', function () {
    $r = ReportPeriod::range('week', rptnow());
    expect($r)->toBe(['from' => '2027-01-08', 'to' => '2027-01-14', 'prev_from' => '2027-01-01', 'prev_to' => '2027-01-07', 'days' => 7]);
    $m = ReportPeriod::range('month', rptnow());
    expect($m['from'])->toBe('2026-12-16')->and($m['to'])->toBe('2027-01-14')->and($m['prev_from'])->toBeNull()->and($m['days'])->toBe(30);
    expect(ReportPeriod::range('year', rptnow()))->toBeNull();
});

it('reports nothing when there is no data at all', function () {
    expect(ReportBuilder::build(rptin())['error'])->toBe('no_data');
    expect(ReportBuilder::build(rptin(['social' => ['posts' => [], 'reactions' => 0, 'comments' => 0, 'shares' => 0, 'reach' => 0, 'top' => null]]))['error'])->toBe('no_data');
});

it('builds a summary, a top-campaign section and a table per ad account, in its own currency', function () {
    $meta = [rptrow('A1', 'BDT', 'Eid Sale', '1200.50', 50000, 800, '40'), rptrow('A1', 'BDT', 'Brand', '300.00', 10000, 100, '5')];
    $goog = [rptrow('G1', 'USD', 'Search Eid', '45.25', 4000, 300, '12.5', '2027-01-15 08:00:00', 'Shop Google')];
    $r = ReportBuilder::build(rptin(['ads' => ['meta' => $meta, 'google' => $goog]]));
    expect($r['error'])->toBeNull();
    $d = $r['doc'];
    expect($d['title'])->toBe('Weekly Marketing Report')->and($d['client_name'])->toBe('Acme')->and($d['language'])->toBe('en')->and($d['date_text'])->toBe('15 Jan 2027')->and($d['brand']['name'])->toBe('My Agency');
    expect($d['sections'][0]['heading'])->toBe('Summary');
    $sum = $d['sections'][0]['body'];
    expect(str_contains($sum, '08 Jan 2027 – 14 Jan 2027'))->toBeTrue()->and(str_contains($sum, 'Spend BDT 1,500.50'))->toBeTrue()->and(str_contains($sum, 'Clicks 900'))->toBeTrue()->and(str_contains($sum, 'Results 45'))->toBeTrue()
        ->and(str_contains($sum, 'Cost per result BDT 33.34'))->toBeTrue()->and(str_contains($sum, 'Spend USD 45.25'))->toBeTrue()->and(str_contains($sum, 'Conversions 12.5'))->toBeTrue()->and(str_contains($sum, 'CTR 1.50%'))->toBeTrue();
    expect(count($d['pricing']['rows']))->toBe(2)->and($d['pricing']['rows'][0]['unit_price'])->toBe('BDT 1,500.50')->and($d['pricing']['rows'][1]['unit_price'])->toBe('USD 45.25')->and($d['pricing']['rows'][1]['total'])->toBe('12.5')
        ->and($d['pricing']['totals'])->toBe([])->and($d['pricing']['note'] !== '')->toBeTrue();
    $top = array_values(array_filter($d['sections'], fn ($s) => str_starts_with($s['heading'], 'Top campaigns')));
    expect(count($top))->toBe(2)->and(str_contains($top[0]['body'], '- Eid Sale — Spend BDT 1,200.50, Clicks 800, Results 40'))->toBeTrue();
    expect(end($d['sections'])['heading'])->toBe('Sources and notes');
    expect(str_contains(rpttext($d), 'BDT 1,500.50 + USD'))->toBeFalse();
});

it('compares with the previous week only where the base is non-zero', function () {
    $cur = [rptrow('A1', 'BDT', 'Eid', '150.00', 5000, 120, '15')];
    $prev = [rptrow('A1', 'BDT', 'Eid', '100.00', 4000, 100, '0')];
    $r = ReportBuilder::build(rptin(['ads' => ['meta' => $cur, 'google' => []], 'prev' => ['meta' => $prev, 'google' => []]]));
    $sum = $r['doc']['sections'][0]['body'];
    expect(str_contains($sum, '(vs previous week: Spend +50%, Clicks +20%)'))->toBeTrue()->and(str_contains($sum, 'Results +'))->toBeFalse();
    $r = ReportBuilder::build(rptin(['kind' => 'month', 'range' => ReportPeriod::range('month', rptnow()), 'ads' => ['meta' => $cur, 'google' => []], 'prev' => []]));
    expect(str_contains($r['doc']['sections'][0]['body'], 'vs previous'))->toBeFalse()->and($r['doc']['title'])->toBe('Monthly Marketing Report');
    $down = ReportBuilder::build(rptin(['ads' => ['meta' => [rptrow('A1', 'BDT', 'Eid', '50.00', 5000, 90, '3')], 'google' => []], 'prev' => ['meta' => $prev, 'google' => []]]));
    expect(str_contains($down['doc']['sections'][0]['body'], 'Spend −50%'))->toBeTrue()->and(str_contains($down['doc']['sections'][0]['body'], 'Clicks −10%'))->toBeTrue();
});

it('warns about stale or never-synced accounts', function () {
    foreach (['2027-01-10 09:00:00', null, 'not a date'] as $when) {
        $r = ReportBuilder::build(rptin(['ads' => ['meta' => [rptrow('A1', 'BDT', 'Eid', '100.00', 5000, 90, '3', $when)], 'google' => []]]));
        $top = array_values(array_filter($r['doc']['sections'], fn ($s) => str_starts_with($s['heading'], 'Top campaigns')));
        expect(str_contains($top[0]['body'], 'Warning:'))->toBeTrue();
    }
    $ok = ReportBuilder::build(rptin(['ads' => ['meta' => [rptrow('A1', 'BDT', 'Eid', '100.00', 5000, 90, '3', '2027-01-14 12:00:00')], 'google' => []]]));
    expect(str_contains(rpttext($ok['doc']), 'Warning:'))->toBeFalse();
});

it('lists at most 8 campaigns per account and cleans names', function () {
    $rows = [];
    for ($i = 1; $i <= 12; $i++) {
        $rows[] = rptrow('A1', 'BDT', "Camp\n$i ".str_repeat('x', 120), (string) (1000 - $i), 100, 10, '1');
    }
    $r = ReportBuilder::build(rptin(['ads' => ['meta' => $rows, 'google' => []]]));
    $top = array_values(array_filter($r['doc']['sections'], fn ($s) => str_starts_with($s['heading'], 'Top campaigns')));
    $lines = array_filter(explode("\n", $top[0]['body']), fn ($l) => str_starts_with($l, '- '));
    expect(count($lines))->toBe(8)->and(str_contains($top[0]['body'], "Camp\n"))->toBeFalse();
    foreach ($lines as $l) {
        expect(mb_strlen($l) < 200)->toBeTrue();
    }
});

it('writes Bengali with Bengali digits, and keeps the typed notes exactly', function () {
    $notes = "- ৫টি নতুন বিজ্ঞাপন বানানো হয়েছে\n- ল্যান্ডিং পেজ ঠিক করা হয়েছে\x07";
    $r = ReportBuilder::build(rptin(['language' => 'bn', 'notes' => $notes, 'ads' => ['meta' => [rptrow('A1', 'BDT', 'ঈদ অফার', '1200.50', 50000, 800, '40')], 'google' => []]]));
    $d = $r['doc'];
    expect($d['title'])->toBe('সাপ্তাহিক মার্কেটিং রিপোর্ট')->and($d['language'])->toBe('bn')->and($d['date_text'])->toBe('১৫ জানুয়ারি ২০২৭');
    expect(str_contains($d['sections'][0]['body'], 'খরচ ১,২০০.৫০ BDT'))->toBeTrue()->and(str_contains($d['sections'][0]['body'], 'ক্লিক ৮০০'))->toBeTrue()->and(str_contains($d['sections'][0]['body'], '০৮ জানুয়ারি ২০২৭'))->toBeTrue();
    expect($d['sections'][1]['heading'])->toBe('What we did in this period')->and($d['sections'][1]['body'])->toBe("- ৫টি নতুন বিজ্ঞাপন বানানো হয়েছে\n- ল্যান্ডিং পেজ ঠিক করা হয়েছে");
    expect($d['labels']['item'])->toBe('Account')->and($d['pricing']['rows'][0]['qty'])->toBe('৮০০');
    $long = ReportBuilder::build(rptin(['notes' => str_repeat('a', 5000), 'ads' => ['meta' => [rptrow('A1', 'BDT', 'x', '1.00', 10, 1, '0')], 'google' => []]]));
    expect(mb_strlen($long['doc']['sections'][1]['body']))->toBe(3000);
});

it('adds the social section and counts a social-only period as data', function () {
    $s = ['posts' => [['platform' => 'facebook', 'count' => 4], ['platform' => 'youtube', 'count' => 1]], 'reactions' => 120, 'comments' => 15, 'shares' => 9, 'reach' => 5400, 'top' => ['title' => 'Eid post', 'platform' => 'facebook', 'reactions' => 80]];
    $r = ReportBuilder::build(rptin(['social' => $s]));
    expect($r['error'])->toBeNull();
    $d = $r['doc'];
    expect(isset($d['pricing']))->toBeFalse()->and(str_contains($d['sections'][0]['body'], 'Social media: Posts published 5 · Reactions 120 · Reach 5,400'))->toBeTrue();
    $soc = array_values(array_filter($d['sections'], fn ($x) => $x['heading'] === 'Social media'));
    expect(str_contains($soc[0]['body'], '- Facebook: 4'))->toBeTrue()->and(str_contains($soc[0]['body'], '- YouTube: 1'))->toBeTrue()->and(str_contains($soc[0]['body'], 'Best-performing post: Eid post (Facebook, Reactions 80)'))->toBeTrue();
});

it('fits the renderer limits and uses the brand kit', function () {
    $brand = ['name' => 'Studio X', 'primary_color' => '#112233', 'accent_color' => '#445566', 'logo_b64' => null, 'email' => 'a@b.co', 'phone' => '', 'website' => '', 'address' => ''];
    $rows = [];
    for ($i = 1; $i <= 5; $i++) {
        $rows[] = rptrow("A$i", 'BDT', 'Camp', '99999999.99', 999999999, 99999999, '99999999', '2027-01-15 09:00:00', str_repeat('At', 150));
    }
    $d = ReportBuilder::build(rptin(['brand' => $brand, 'client_name' => str_repeat('c', 300), 'ads' => ['meta' => $rows, 'google' => $rows]]))['doc'];
    expect($d['brand']['name'])->toBe('Studio X')->and($d['brand']['primary'])->toBe('#112233')->and(mb_strlen($d['client_name']))->toBe(200)->and(count($d['sections']) <= 30)->toBeTrue();
    foreach ($d['pricing']['rows'] as $r) {
        expect(mb_strlen($r['name']) <= 240 && mb_strlen($r['qty']) <= 16 && mb_strlen($r['unit_price']) <= 32 && mb_strlen($r['total']) <= 32)->toBeTrue();
    }
    foreach ($d['sections'] as $s) {
        expect(mb_strlen($s['heading']) <= 160 && mb_strlen($s['body']) <= 8000)->toBeTrue();
    }
    expect(mb_strlen($d['title']) <= 200)->toBeTrue();
});

function rptsvc(): array
{
    $repo = new FakeReportRepo;
    $eng = new FakeProposalEngine;

    return [new ReportService($repo, $eng), $repo, $eng];
}

it('the service gathers the right periods, social only when allowed', function () {
    [$s, $repo] = rptsvc();
    $repo->ads['meta:2027-01-08'] = [rptrow('A1', 'BDT', 'Eid', '100.00', 5000, 90, '3')];
    $repo->socialData['posts'] = [['platform' => 'facebook', 'count' => 1]];
    $b = $s->build(['kind' => 'week', 'language' => 'en'], 'Asia/Dhaka', true, rptnow());
    expect($b['error'])->toBeNull()->and($b['range']['from'])->toBe('2027-01-08');
    $names = array_map(fn ($c) => $c[0].':'.implode('|', $c[1]), $repo->calls);
    expect($names)->toBe(['ads:meta|2027-01-08|2027-01-14', 'ads:google|2027-01-08|2027-01-14', 'ads:meta|2027-01-01|2027-01-07', 'ads:google|2027-01-01|2027-01-07', 'social:2027-01-08|2027-01-14|Asia/Dhaka']);
    [$s, $repo] = rptsvc();
    $repo->ads['meta:2027-01-08'] = [rptrow('A1', 'BDT', 'Eid', '100.00', 5000, 90, '3')];
    $s->build(['kind' => 'month', 'language' => 'en'], 'Asia/Dhaka', false, rptnow());
    expect(array_column($repo->calls, 0))->toBe(['ads', 'ads']);
    [$s] = rptsvc();
    expect($s->build(['kind' => 'decade', 'language' => 'en'], 'Asia/Dhaka', true, rptnow())['error'])->toBe('bad_period');
});

it('the service renders through the engine, names the file by period, and reports problems', function () {
    [$s, $repo, $eng] = rptsvc();
    $repo->ads['meta:2027-01-08'] = [rptrow('A1', 'BDT', 'Eid', '100.00', 5000, 90, '3')];
    $r = $s->render('T1', 'U1', ['kind' => 'week', 'language' => 'bn'], 'Asia/Dhaka', true, 'pdf', rptnow());
    expect($r['ok'])->toBeTrue()->and($r['filename'])->toBe('report-week-2027-01-14.pdf')->and($r['mime'])->toBe('application/pdf')->and($eng->calls[0]['tenantId'])->toBe('T1')->and($eng->calls[0]['userId'])->toBe('U1')->and($eng->calls[0]['format'])->toBe('pdf')->and($eng->calls[0]['payload']['language'])->toBe('bn');
    $d = $s->render('T1', 'U1', ['kind' => 'week', 'language' => 'en'], 'Asia/Dhaka', true, 'docx', rptnow());
    expect($d['filename'])->toEndWith('.docx')->and(str_contains($d['mime'], 'wordprocessingml'))->toBeTrue();
    $eng->pdfDown = true;
    expect($s->render('T1', 'U1', ['kind' => 'week', 'language' => 'en'], 'Asia/Dhaka', true, 'pdf', rptnow()))->toBe(['ok' => false, 'error' => 'unavailable']);
    expect($s->render('T1', 'U1', ['kind' => 'week', 'language' => 'en'], 'Asia/Dhaka', true, 'docx', rptnow())['ok'])->toBeTrue();
    [$s2, , $eng2] = rptsvc();
    expect($s2->render('T1', 'U1', ['kind' => 'week', 'language' => 'en'], 'Asia/Dhaka', true, 'pdf', rptnow()))->toBe(['ok' => false, 'error' => 'no_data'])->and($eng2->calls)->toBe([]);
});

it('never divides by zero or shows meaningless ratios', function () {
    $r = ReportBuilder::build(rptin(['ads' => ['meta' => [rptrow('A1', 'BDT', 'Free', '0.00', 100, 7, '0')], 'google' => []]]));
    $b = $r['doc']['sections'][0]['body'];
    expect(str_contains($b, 'Cost per click'))->toBeFalse()->and(str_contains($b, 'Cost per result'))->toBeFalse()->and(str_contains($b, 'CTR 7.00%'))->toBeTrue();
    $r = ReportBuilder::build(rptin(['ads' => ['meta' => [rptrow('A1', 'BDT', 'NoImp', '5.00', 0, 3, '1')], 'google' => []]]));
    expect($r['error'])->toBeNull()->and(str_contains($r['doc']['sections'][0]['body'], 'CTR'))->toBeFalse()->and(str_contains($r['doc']['sections'][0]['body'], 'Cost per click BDT 1.67'))->toBeTrue();
});

it('stale means older than two days', function () {
    foreach (['2027-01-12 12:00:00' => true, '2027-01-13 12:00:00' => false] as $when => $stale) {
        $r = ReportBuilder::build(rptin(['ads' => ['meta' => [rptrow('A1', 'BDT', 'Eid', '100.00', 5000, 90, '3', $when)], 'google' => []]]));
        expect(str_contains(rpttext($r['doc']), 'Warning:'))->toBe($stale);
    }
});

it('caps sections at 30 and table rows at 60 for very large agencies', function () {
    $rows = [];
    for ($i = 1; $i <= 65; $i++) {
        $rows[] = rptrow("A$i", 'BDT', 'Camp', '10.00', 100, 5, '1', '2027-01-15 09:00:00', "Account $i");
    }
    $d = ReportBuilder::build(rptin(['notes' => 'x', 'ads' => ['meta' => $rows, 'google' => []], 'social' => ['posts' => [['platform' => 'facebook', 'count' => 1]], 'reactions' => 1, 'comments' => 0, 'shares' => 0, 'reach' => 1, 'top' => null]]))['doc'];
    expect(count($d['sections']))->toBe(30)->and(count($d['pricing']['rows']))->toBe(60);
});

it('a social-only period with just reach still counts as data', function () {
    $r = ReportBuilder::build(rptin(['social' => ['posts' => [], 'reactions' => 0, 'comments' => 0, 'shares' => 0, 'reach' => 50, 'top' => null]]));
    expect($r['error'])->toBeNull();
    $r = ReportBuilder::build(rptin(['social' => ['posts' => [], 'reactions' => 3, 'comments' => 0, 'shares' => 0, 'reach' => 0, 'top' => null]]));
    expect($r['error'])->toBeNull();
});
