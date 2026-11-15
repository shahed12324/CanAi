<?php

use App\Crm\BdPhone;
use App\Crm\CrmService;
use App\Crm\CsvImporter;
use App\Crm\LeadCsv;
use App\Crm\TagNormalizer;
use Tests\Support\FakeCrmStore;
use Tests\Support\FakeLeadStore;

const CT = 'TENANT1000000000000000000A';
const CU = 'USER10000000000000000000AA';

function crm(?string $now = null): array
{
    $leads = new FakeLeadStore;
    $store = new FakeCrmStore($leads);
    $clock = $now === null ? null : fn () => new DateTimeImmutable($now, new DateTimeZone('UTC'));
    $c = $leads->createCompany(CT, ['name' => 'Alo', 'domain' => 'alo.com', 'score' => 50]);
    $leads->ensureLead(CT, $c, 50, 'research', null, null);
    $leadId = array_key_first($leads->leads);

    return [new CrmService($store, $clock), $store, $leadId, $leads];
}

it('cleans tags: lowercase, bengali kept, no junk, max 10, no repeats', function () {
    expect(TagNormalizer::parse('VIP, ঢাকা , web design;;VIP, <b>x</b>')['tags'])->toBe(['vip', 'Dhaka', 'web-design', 'bxb']);
    $many = TagNormalizer::parse(implode(',', range(1, 14)));
    expect($many['tags'])->toHaveCount(10)->and($many['truncated'])->toBeTrue();
    expect(TagNormalizer::parse(str_repeat('a', 80))['tags'][0])->toHaveLength(30);
    expect(TagNormalizer::parse(" , ,\n -- ,")['tags'])->toBe([]);
});

it('normalises phones the way people type them', function () {
    foreach (['01712-345678', '+880 1712 345678', '8801712345678', '০১৭১২৩৪৫৬৭৮', '008801712345678', '(+88) 01712345678'] as $ok) {
        expect(BdPhone::normalize($ok))->toBe('+8801712345678');
    }
    expect(BdPhone::normalize('+1 415 555 0123'))->toBe('+14155550123');
    foreach (['14155550123', '017123', '02-9876543', '', 'abc', '+880171234567', '01212345678', '+0123456789'] as $bad) {
        expect(BdPhone::normalize($bad))->toBeNull();
    }
});

it('parses an import CSV: aliases, BOM, semicolons, multi-line notes, duplicates, junk, Bengali stage', function () {
    $csv = "\xEF\xBB\xBFCompany;Website;Email;Mobile;Tags;ধাপ;Note\n"
        ."Alo;https://www.alo.com/about;Info@Alo.com, bad@;01712345678;VIP,ঢাকা;যোগ্য;\"line1\nline2\"\n"
        ."Alo again;alo.com;;;;;\n"
        ."Bad;not a site;;;;;\n"
        ."Beta;beta.com.bd;;;;weird;\n";
    $r = LeadCsv::parse($csv, 10);
    expect($r['error'])->toBeNull()->and($r['rows'])->toHaveCount(2)->and($r['duplicates'])->toBe(1);
    expect($r['rows'][0])->toMatchArray(['domain' => 'alo.com', 'name' => 'Alo', 'emails' => ['info@alo.com'], 'phones' => ['+8801712345678'], 'tags' => ['vip', 'Dhaka'], 'stage' => 'qualified', 'note' => "line1\nline2"]);
    expect($r['rows'][1]['stage'])->toBeNull();
    expect($r['rejected'])->toHaveCount(1)->and($r['rejected'][0]['line'])->toBe(4);       // the record's row number, as a spreadsheet shows it
});

it('refuses a CSV without a website column and caps the row count', function () {
    expect(LeadCsv::parse("name,email\nA,a@b.com\n", 10)['error'])->toBe('no_website_column');
    expect(LeadCsv::parse('', 10)['error'])->toBe('empty');
    $big = LeadCsv::parse("website\n".implode("\n", array_map(fn ($i) => "s$i.com", range(1, 9)))."\n", 4);
    expect($big['rows'])->toHaveCount(4)->and($big['truncated'])->toBeTrue();
});

it('writes CSV that spreadsheets cannot turn into formulas', function () {
    expect(LeadCsv::safeCell('=HYPERLINK("http://evil")'))->toBe("'=HYPERLINK(\"http://evil\")");
    foreach (['+cmd|x', '-1+1', '@SUM(1)', "\tx", "\rx"] as $v) {
        expect(LeadCsv::safeCell($v))->toStartWith("'");
    }
    expect(LeadCsv::safeCell('+8801712345678'))->toBe('+8801712345678')->and(LeadCsv::safeCell('আলো'))->toBe('আলো');
    $out = LeadCsv::toCsv([['name', 'note'], ['A, "B"', "=1+1\nx"]]);
    expect($out)->toStartWith("\xEF\xBB\xBFname,note\r\n")->and($out)->toContain('"A, ""B"""')->and($out)->toContain("\"'=1+1\nx\"");
});

it('moves stages once, logs once, and rejects unknown stages', function () {
    [$svc, $store, $id] = crm();
    expect($svc->moveStage($id, 'contacted', CU))->toBe(['ok' => true, 'from' => 'new']);
    expect($svc->moveStage($id, 'contacted', CU)['ok'])->toBeTrue();
    expect($store->activities)->toHaveCount(1)->and($store->activities[0]['meta'])->toBe(['from' => 'new', 'to' => 'contacted']);
    expect($svc->moveStage($id, 'banana', CU))->toBe(['ok' => false, 'error' => 'bad_stage']);
    expect($svc->moveStage('NOPE', 'won', CU))->toBe(['ok' => false, 'error' => 'not_found']);
});

it('validates notes and tasks', function () {
    [$svc, $store, $id] = crm();
    expect($svc->addNote($id, CU, "  \n ")['error'])->toBe('bad_note');
    expect($svc->addNote($id, CU, str_repeat('ক', 2001))['error'])->toBe('bad_note');
    expect($svc->addNote('NOPE', CU, 'hi')['error'])->toBe('not_found');
    expect($svc->addNote($id, CU, ' কল করেছি ')['ok'])->toBeTrue()->and($store->activities[0]['body'])->toBe('কল করেছি');

    expect($svc->addTask($id, CU, '', null, 'Asia/Dhaka')['error'])->toBe('bad_title');
    expect($svc->addTask($id, CU, 'Call', '2026-02-31', 'Asia/Dhaka')['error'])->toBe('bad_date');
    expect($svc->addTask($id, CU, 'Call', 'tomorrow', 'Asia/Dhaka')['error'])->toBe('bad_date');
    $t = $svc->addTask($id, CU, 'Call', '2026-11-05T14:30', 'Asia/Dhaka');
    expect($t['ok'])->toBeTrue()->and($store->tasks[$t['id']]['due'])->toBe('2026-11-05 08:30:00+00:00')->and($store->tasks[$t['id']]['assignee'])->toBe(CU);
    $d = $svc->addTask($id, CU, 'Email', '2026-11-05', 'Asia/Dhaka');
    expect($store->tasks[$d['id']]['due'])->toBe('2026-11-05 03:00:00+00:00');
    expect($svc->addTask($id, CU, 'No date', null, 'Asia/Dhaka')['ok'])->toBeTrue();
});

it('completes a task only once', function () {
    [$svc, $store, $id] = crm();
    $t = $svc->addTask($id, CU, 'Call', null, 'UTC')['id'];
    $before = count($store->activities);
    expect($svc->completeTask($t, CU)['ok'])->toBeTrue()->and(count($store->activities))->toBe($before + 1);
    expect($svc->completeTask($t, CU))->toBe(['ok' => false, 'error' => 'not_found'])->and(count($store->activities))->toBe($before + 1);
});

it('sets tags through the normaliser and logs them', function () {
    [$svc, $store, $id, $leads] = crm();
    $r = $svc->setTags($id, CU, 'Hot, ঢাকা,hot');
    expect($r['tags'])->toBe(['hot', 'Dhaka'])->and($leads->leads[$id]['tags'])->toBe(['hot', 'Dhaka']);
    expect($svc->setTags('NOPE', CU, 'x')['error'])->toBe('not_found');
});

it('handles follow-up dates in the workspace timezone', function () {
    // 2026-11-05 20:00 UTC is already 06 Nov in Dhaka
    [$svc, $store, $id, $leads] = crm('2026-11-05 20:00:00');
    expect($svc->setFollowUp($id, CU, '2026-11-05', 'Asia/Dhaka')['error'])->toBe('past_date');
    expect($svc->setFollowUp($id, CU, '2026-11-06', 'Asia/Dhaka')['ok'])->toBeTrue();
    expect($leads->leads[$id]['follow_up'])->toBe('2026-11-06 03:00:00+00:00');
    expect($svc->setFollowUp($id, CU, 'soon', 'Asia/Dhaka')['error'])->toBe('bad_date');
    expect($svc->setFollowUp($id, CU, '', 'Asia/Dhaka')['ok'])->toBeTrue()->and($leads->leads[$id]['follow_up'])->toBeNull();
    $svc->moveStage($id, 'won', CU);
    expect($svc->setFollowUp($id, CU, '2026-11-20', 'Asia/Dhaka')['error'])->toBe('lead_closed');
    expect($svc->setFollowUp($id, CU, null, 'Asia/Dhaka')['ok'])->toBeTrue();
});

it('imports CSV rows without duplicates and keeps existing leads untouched', function () {
    $leads = new FakeLeadStore;
    $crm = new FakeCrmStore($leads);
    $imp = new CsvImporter($leads, $crm);
    $csv = "website,name,email,phone,tags,stage,note\nalo.com,Alo,info@alo.com,01712345678,vip,qualified,call him\nbeta.com,,,,,,\n";
    $r = $imp->import(CT, CU, LeadCsv::parse($csv, 100));
    expect($r)->toBe(['created' => 2, 'existing' => 0, 'contacts_added' => 2, 'contacts_skipped' => 0]);
    $alo = $crm->leadForCompany($leads->findCompany(CT, 'alo.com')['id']);
    expect($leads->leads[$alo]['stage'])->toBe('qualified')->and($leads->leads[$alo]['tags'])->toBe(['vip']);
    expect($leads->findCompany(CT, 'beta.com')['name'])->toBe('beta.com');
    expect(array_values(array_filter($crm->activities, fn ($a) => $a['kind'] === 'note')))->toHaveCount(1);

    // the same file again, but this time asking for another stage/tags: nothing is overwritten, nothing doubled
    $again = $imp->import(CT, CU, LeadCsv::parse("website,email,phone,tags,stage\nalo.com,info@alo.com,01712345678,other,lost\n", 100));
    expect($again)->toBe(['created' => 0, 'existing' => 1, 'contacts_added' => 0, 'contacts_skipped' => 2]);
    expect($leads->leads[$alo]['stage'])->toBe('qualified')->and($leads->leads[$alo]['tags'])->toBe(['vip'])->and($leads->leads)->toHaveCount(2);
});

it('stops adding contacts at the per-company cap', function () {
    $leads = new FakeLeadStore;
    $imp = new CsvImporter($leads, new FakeCrmStore($leads));
    $emails = implode(' ', array_map(fn ($i) => "p$i@alo.com", range(1, 5)));
    $imp->import(CT, CU, LeadCsv::parse("website,email,phone\nalo.com,\"$emails\",\"01711111111;01722222222;01733333333;01744444444;01755555555\"\n", 10));
    $r = $imp->import(CT, CU, LeadCsv::parse("website,email\nalo.com,more@alo.com\n", 10));
    expect($r['contacts_skipped'])->toBe(1)->and($leads->contactCount($leads->findCompany(CT, 'alo.com')['id']))->toBe(10);
});
