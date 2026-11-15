<?php

use App\Proposals\DocDate;
use App\Proposals\DocumentBuilder;
use App\Proposals\Money;
use App\Proposals\Pricing;
use App\Proposals\ProposalService;
use App\Proposals\TemplateFiller;
use Tests\Support\FakeCrmStore;
use Tests\Support\FakeLeadStore;
use Tests\Support\FakeProposalEngine;
use Tests\Support\FakeProposalRepo;

const PT = 'TENANT1000000000000000000A';
const PU = 'USER10000000000000000000AA';
const PL = 'LEAD10000000000000000000AA';

it('prints taka the Bangladeshi way and plainly in English', function () {
    expect(Money::format(125_000_000, 'bn'))->toBe('৳ ১২,৫০,০০০');
    expect(Money::format(6_250_050, 'bn'))->toBe('৳ ৬২,৫০০.৫০');
    foreach ([[0, '৳ ০'], [10_000, '৳ ১০০'], [100_000, '৳ ১,০০০'], [9_999_900, '৳ ৯৯,৯৯৯'], [10_000_000, '৳ ১,০০,০০০'], [1_234_567_800, '৳ ১,২৩,৪৫,৬৭৮']] as [$p, $want]) {
        expect(Money::format($p, 'bn'))->toBe($want);
    }
    expect(Money::format(125_000_000, 'en'))->toBe('BDT 1,250,000')->and(Money::format(5, 'en'))->toBe('BDT 0.05');
});

it('parses amounts people type and refuses the rest', function () {
    foreach ([['12,500', 1_250_000], ['৳ ১২৫০০.৫', 1_250_050], ['BDT 50000', 5_000_000], ['1500 tk', 150_000], ['০.৫', 50], ['100000000', 10_000_000_000], ['0', 0]] as [$in, $want]) {
        expect(Money::parse($in))->toBe($want);
    }
    foreach (['', '-5', '1e3', '12.345', '100000001', '100000000.01', '1,2,3x', 'abc', '5.', '.5'] as $bad) {
        expect(Money::parse($bad))->toBeNull();
    }
});

it('parses the price table lines', function () {
    $r = Pricing::parseLines("ওয়েবসাইট ডিজাইন | ১ | ৫০,০০০\n\nSEO | 12500\nলোগো | ২ | 3,000.50");
    expect($r['errors'])->toBe([])->and($r['items'])->toHaveCount(3);
    expect($r['items'][0])->toBe(['name' => 'Website design', 'qty' => 1, 'unit_minor' => 5_000_000]);
    expect($r['items'][1])->toBe(['name' => 'SEO', 'qty' => 1, 'unit_minor' => 1_250_000]);
    expect($r['items'][2])->toBe(['name' => 'Logo', 'qty' => 2, 'unit_minor' => 300_050]);
    $bad = Pricing::parseLines("only name\n | 1 | 5\nX | 0 | 5\nX | 10001 | 5\nX | 1 | abc\nA | 1 | 2 | 3\nOK | 1 | 5");
    expect($bad['items'])->toHaveCount(1)->and(array_column($bad['errors'], 'line'))->toBe([1, 2, 3, 4, 5, 6]);
    $many = Pricing::parseLines(implode("\n", array_map(fn ($i) => "item $i | 10", range(1, 60))));
    expect($many['items'])->toHaveCount(50)->and($many['errors'])->toHaveCount(1);
});

it('parses percentages into basis points', function () {
    foreach ([['15', 1500], ['7.5', 750], ['১৫%', 1500], ['', 0], ['100', 10_000], ['0.25', 25], [' 5 % ', 500]] as [$in, $want]) {
        expect(Pricing::parsePercent($in))->toBe($want);
    }
    foreach (['100.01', '101', '-1', '1.234', 'abc', '5%%x'] as $bad) {
        expect(Pricing::parsePercent($bad))->toBeNull();
    }
    expect(Pricing::formatPercent(750, 'bn'))->toBe('৭.৫')->and(Pricing::formatPercent(1500, 'en'))->toBe('15')->and(Pricing::formatPercent(25, 'en'))->toBe('0.25');
});

it('does the sums in whole paisa with half-up rounding and no overflow', function () {
    expect(Pricing::mulBp(1005, 1500))->toBe(151)->and(Pricing::mulBp(1010, 500))->toBe(51)->and(Pricing::mulBp(1009, 500))->toBe(50);
    expect(Pricing::mulBp(4_000_000_000_000_000_000, 10_000))->toBe(4_000_000_000_000_000_000);
    $c = Pricing::compute([['name' => 'A', 'qty' => 2, 'unit_minor' => 5_000_000], ['name' => 'B', 'qty' => 1, 'unit_minor' => 1_250_050]], 1000, 1500);
    expect($c['subtotal'])->toBe(11_250_050)->and($c['discount'])->toBe(1_125_005)->and($c['vat'])->toBe(1_518_757)->and($c['total'])->toBe(11_643_802);    // vat on the discounted amount
    expect($c['rows'][0]['total_minor'])->toBe(10_000_000);
    expect(Pricing::compute([], 0, 0)['total'])->toBe(0);
    $max = Pricing::compute(array_fill(0, 50, ['name' => 'x', 'qty' => 10_000, 'unit_minor' => 10_000_000_000]), 0, 1500);
    expect($max['subtotal'])->toBe(5_000_000_000_000_000)->and($max['total'])->toBeLessThan(PHP_INT_MAX);
});

it('fills placeholders once and never re-expands a value', function () {
    $v = ['client_name' => 'আলো {{brand_name}}', 'brand_name' => 'Jarvis', 'domain' => 'alo.com'];
    $out = TemplateFiller::fill([['heading' => 'To {{ client_name }}', 'body' => "{{domain}} / {{unknown}} / {{CLIENT_NAME}} / {{brand_name}}"], 'junk'], $v);
    expect($out)->toHaveCount(1);
    expect($out[0]['heading'])->toBe('To আলো {{brand_name}}')->and($out[0]['body'])->toBe('alo.com /  / {{CLIENT_NAME}} / Jarvis');
});

it('writes the gaps list in Bangla or English, capped and with a fallback', function () {
    $a = ['gaps' => [['code' => 'no_fb_pixel', 'label' => 'Facebook Pixel নেই', 'service' => 'Meta Ads ট্র্যাকিং'], ['code' => 'no_phone', 'label' => "ফোন \n নম্বর নেই", 'service' => ''], ['label' => ''], 'junk']];
    expect(TemplateFiller::gapsList($a, 'bn'))->toBe("- Facebook Pixel নেই → Meta Ads ট্র্যাকিং\n- ফোন নম্বর নেই");
    expect(TemplateFiller::gapsList($a, 'en'))->toBe("- No Facebook Pixel → Meta Ads tracking and advertising\n- No phone number shown → Contact information improvements");
    expect(TemplateFiller::gapsList([], 'bn'))->toContain('এখানে')->and(TemplateFiller::gapsList([], 'en'))->toContain('describe');
    $many = ['gaps' => array_fill(0, 20, ['code' => 'no_https', 'label' => 'x', 'service' => 'y'])];
    expect(substr_count(TemplateFiller::gapsList($many, 'bn'), "\n"))->toBe(7);
});

it('formats the document date', function () {
    $d = new DateTimeImmutable('2026-11-02 10:00:00');
    expect(DocDate::format($d, 'bn'))->toBe('০২ নভেম্বর ২০২৬')->and(DocDate::format($d, 'en'))->toBe('02 Nov 2026');
});

it('builds the engine payload with localised totals', function () {
    $in = ['title' => 'Proposals', 'language' => 'bn', 'client_name' => 'আলো', 'sections' => [['heading' => 'H', 'body' => 'B'], 'x'], 'date' => new DateTimeImmutable('2026-11-02'),
        'pricing' => ['items' => [['name' => 'Web', 'qty' => 2, 'unit_minor' => 5_000_000]], 'discount_bp' => 1000, 'vat_bp' => 750, 'note' => 'n'],
        'portfolio' => [['title' => 'Shop', 'url' => 'https://a.test']],
        'brand' => ['name' => 'Jarvis', 'primary_color' => '#112233', 'accent_color' => '#445566', 'logo_b64' => null, 'email' => 'a@b.test', 'phone' => null, 'website' => null, 'address' => null]];
    $d = DocumentBuilder::build($in);
    expect($d['language'])->toBe('bn')->and($d['date_text'])->toBe('০২ নভেম্বর ২০২৬')->and($d['sections'])->toHaveCount(1)->and($d['brand']['primary'])->toBe('#112233')->and($d['brand']['logo_b64'])->toBeNull();
    expect(array_column($d['pricing']['totals'], 'label'))->toBe(['Subtotal', 'ছাড় (১০%)', 'ভ্যাট (৭.৫%)', 'Grand total']);
    expect(array_column($d['pricing']['totals'], 'value'))->toBe(['৳ ১,০০,০০০', '-৳ ১০,০০০', '৳ ৬,৭৫০', '৳ ৯৬,৭৫০']);
    expect($d['pricing']['totals'][3]['strong'])->toBeTrue()->and($d['pricing']['rows'][0])->toBe(['name' => 'Web', 'qty' => '২', 'unit_price' => '৳ ৫০,০০০', 'total' => '৳ ১,০০,০০০']);
    expect($d['labels']['pricing'])->toBe('Value')->and($d['brand']['phone'])->toBe('');

    $plain = DocumentBuilder::build(['title' => 'T', 'language' => 'en', 'date' => new DateTimeImmutable('2026-11-02'), 'pricing' => ['items' => [['name' => 'A', 'qty' => 1, 'unit_minor' => 100_000]]], 'brand_fallback_name' => 'Fallback']);
    expect($plain['pricing']['totals'])->toBe([['label' => 'Total', 'value' => 'BDT 1,000', 'strong' => true]])->and($plain['brand']['name'])->toBe('Fallback')->and($plain['brand']['primary'])->toBe('#1f3a5f');
    expect(array_key_exists('pricing', DocumentBuilder::build(['title' => 'T', 'language' => 'en', 'date' => new DateTimeImmutable('2026-11-02')])))->toBeFalse();
});

function propSvc(?string $now = '2026-11-02 10:00:00'): array
{
    $repo = new FakeProposalRepo;
    $repo->templates['TPL'] = ['id' => 'TPL', 'kind' => 'web_design', 'language' => 'bn', 'name' => 'ওয়েব', 'sections' => [['heading' => 'ভূমিকা', 'body' => 'প্রিয় {{client_name}}, {{domain}} — {{brand_name}}'], ['heading' => 'সুযোগ', 'body' => '{{gaps_list}}']]];
    $repo->templates['TPLEN'] = ['id' => 'TPLEN', 'kind' => 'general', 'language' => 'en', 'name' => 'General', 'sections' => [['heading' => 'Intro', 'body' => 'Dear {{client_name}}']]];
    $repo->brand = ['id' => 'BRAND', 'name' => 'Jarvis', 'primary_color' => '#112233', 'accent_color' => '#445566'];
    $repo->leads[PL] = ['name' => 'আলো ডিজিটাল', 'domain' => 'alo.com', 'analysis' => ['gaps' => [['code' => 'no_fb_pixel', 'label' => 'Facebook Pixel নেই', 'service' => 'Meta Ads']]]];
    $crm = new FakeCrmStore(new FakeLeadStore);
    $eng = new FakeProposalEngine;

    return [new ProposalService($repo, $crm, $eng, fn () => new DateTimeImmutable($now, new DateTimeZone('UTC'))), $repo, $crm, $eng];
}

it('creates a proposal from a template and a lead', function () {
    [$svc, $repo, $crm] = propSvc();
    $r = $svc->createFromTemplate(PT, PU, 'TPL', PL);
    expect($r['ok'])->toBeTrue();
    $p = $repo->proposals[$r['id']];
    expect($p['title'])->toBe('Proposals — আলো ডিজিটাল')->and($p['language'])->toBe('bn')->and($p['lead_id'])->toBe(PL);
    expect($p['data']['sections'][0]['body'])->toBe('প্রিয় আলো ডিজিটাল, alo.com — Jarvis')->and($p['data']['sections'][1]['body'])->toBe('- Facebook Pixel নেই → Meta Ads');
    expect($p['data']['client_name'])->toBe('আলো ডিজিটাল')->and($p['data']['pricing']['items'])->toBe([]);
    expect($crm->activities)->toHaveCount(1)->and($crm->activities[0]['kind'])->toBe('proposal')->and($crm->activities[0]['meta']['event'])->toBe('created');
});

it('creates a proposal without a lead and rejects unknown templates or leads', function () {
    [$svc, $repo, $crm] = propSvc();
    $r = $svc->createFromTemplate(PT, PU, 'TPLEN', null, 'My title');
    $p = $repo->proposals[$r['id']];
    expect($p['title'])->toBe('My title')->and($p['data']['sections'][0]['body'])->toBe('Dear Sir/Madam')->and($crm->activities)->toBe([]);
    expect($svc->createFromTemplate(PT, PU, 'NOPE', null))->toBe(['ok' => false, 'error' => 'template_not_found']);
    expect($svc->createFromTemplate(PT, PU, 'TPL', 'NOPE'))->toBe(['ok' => false, 'error' => 'lead_not_found']);
    expect($repo->proposals)->toHaveCount(1);
});

it('validates and saves an edited proposal', function () {
    [$svc, $repo] = propSvc();
    $id = $svc->createFromTemplate(PT, PU, 'TPL', PL)['id'];
    $repo->portfolio = ['a0000000000000000000000001' => ['id' => 'a0000000000000000000000001', 'title' => 'Mine', 'url' => null, 'description' => null]];
    $ok = $svc->save($id, ['title' => ' নতুন শিরোনাম ', 'language' => 'en', 'client_name' => 'X', 'sections' => [['heading' => 'A', 'body' => 'b'], ['heading' => '', 'body' => ''], ['heading' => 'C', 'body' => '']],
        'pricing_text' => "Web | 2 | 5,000\nSEO | 1000", 'discount' => '10', 'vat' => '15', 'note' => 'n', 'portfolio_ids' => ['a0000000000000000000000001', 'a0000000000000000000000001', 'b0000000000000000000000002', 'bad']]);
    expect($ok)->toBe(['ok' => true, 'errors' => []]);
    $d = $repo->proposals[$id];
    expect($d['title'])->toBe('নতুন শিরোনাম')->and($d['language'])->toBe('en')->and($d['data']['sections'])->toHaveCount(2)->and($d['data']['pricing']['items'])->toHaveCount(2);
    expect($d['data']['pricing']['discount_bp'])->toBe(1000)->and($d['data']['pricing']['vat_bp'])->toBe(1500)->and($d['data']['portfolio_ids'])->toBe(['a0000000000000000000000001']);
    expect($d['status'])->toBe('draft');

    $bad = $svc->save($id, ['title' => '', 'language' => 'fr', 'client_name' => str_repeat('x', 201), 'sections' => [['heading' => str_repeat('h', 161), 'body' => '']], 'pricing_text' => 'oops', 'discount' => '101', 'vat' => 'x', 'note' => str_repeat('n', 1001)]);
    expect($bad['ok'])->toBeFalse()->and(array_keys($bad['errors']))->toBe(['title', 'language', 'client_name', 'sections', 'pricing', 'discount', 'vat', 'note']);
    expect($repo->proposals[$id]['title'])->toBe('নতুন শিরোনাম');                                    // nothing was half-saved
    expect($svc->save('NOPE', ['title' => 'x'])['errors'])->toBe(['_' => 'not_found']);
    $tooMany = $svc->save($id, ['title' => 't', 'sections' => array_fill(0, 31, ['heading' => 'h', 'body' => 'b'])]);
    expect($tooMany['errors']['sections'])->toContain('৩০');
});

it('changes status once and records it on the lead', function () {
    [$svc, $repo, $crm] = propSvc();
    $id = $svc->createFromTemplate(PT, PU, 'TPL', PL)['id'];
    expect($svc->setStatus($id, 'sent', PU))->toBe(['ok' => true]);
    expect($svc->setStatus($id, 'sent', PU))->toBe(['ok' => true]);
    expect($repo->proposals[$id]['status'])->toBe('sent')->and($crm->activities)->toHaveCount(2);                // created + sent, not sent twice
    expect($crm->activities[1]['meta'])->toBe(['event' => 'sent', 'proposal_id' => $id]);
    expect($svc->setStatus($id, 'weird', PU)['error'])->toBe('bad_status')->and($svc->setStatus('NOPE', 'sent', PU)['error'])->toBe('not_found');
});

it('renders through the engine and says so when the PDF engine is down', function () {
    [$svc, $repo, $crm, $eng] = propSvc();
    $id = $svc->createFromTemplate(PT, PU, 'TPL', PL)['id'];
    $svc->save($id, ['title' => 'T', 'sections' => [['heading' => 'h', 'body' => 'b']], 'pricing_text' => 'Web | 1000', 'discount' => '', 'vat' => '']);
    $pdf = $svc->render(PT, PU, $id, 'pdf', 'Asia/Dhaka');
    expect($pdf['ok'])->toBeTrue()->and($pdf['mime'])->toBe('application/pdf')->and($pdf['filename'])->toBe('proposal-p0000000.pdf');
    $call = $eng->calls[0];
    expect($call['tenantId'])->toBe(PT)->and($call['format'])->toBe('pdf')->and($call['payload']['title'])->toBe('T')->and($call['payload']['brand']['name'])->toBe('Jarvis')->and($call['payload']['pricing']['totals'][0]['value'])->toBe('৳ ১,০০০');
    expect($call['payload']['date_text'])->toBe('০২ নভেম্বর ২০২৬');
    $eng->pdfDown = true;
    expect($svc->render(PT, PU, $id, 'pdf', 'UTC'))->toBe(['ok' => false, 'error' => 'unavailable']);
    expect($svc->render(PT, PU, $id, 'docx', 'UTC')['filename'])->toEndWith('.docx');
    expect($svc->render(PT, PU, 'NOPE', 'pdf', 'UTC'))->toBe(['ok' => false, 'error' => 'not_found']);
});

it('uses the workspace timezone for the date on the document', function () {
    [$svc, $repo] = propSvc('2026-11-02 20:30:00');                                       // already 03 Nov in Dhaka
    $id = $svc->createFromTemplate(PT, PU, 'TPL', null)['id'];
    expect($svc->document($id, 'Asia/Dhaka')['date_text'])->toBe('০৩ নভেম্বর ২০২৬')->and($svc->document($id, 'UTC')['date_text'])->toBe('০২ নভেম্বর ২০২৬');
    expect($svc->document($id, 'Not/AZone')['date_text'])->toBe('০৩ নভেম্বর ২০২৬');
});
