<?php

use App\Analyst\AnalystService;
use App\Analyst\AnalystText;
use App\Analyst\Facts;
use App\Analyst\Forecast;
use App\Analyst\NarrativeCheck;
use App\Chat\CreditPricing;
use Tests\Support\FakeAnalystEngine;
use Tests\Support\FakeAnalystPicker;
use Tests\Support\FakeAnalystRepo;
use Tests\Support\FakeLedger;
use Tests\Support\ProviderDown;

const AT = 'TENANT0000000000000000000A';
const AU = 'USER000000000000000000000A';
const AR = 'REPORT00000000000000000000A';
const ATODAY = '2026-11-04';

function asvc(?FakeAnalystRepo $r = null, ?FakeAnalystEngine $e = null, ?FakeLedger $l = null, ?FakeAnalystPicker $p = null): array
{
    $r ??= aRepo();
    $e ??= new FakeAnalystEngine;
    $l ??= new FakeLedger('100.0000');

    return [new AnalystService($r, $e, $p ?? new FakeAnalystPicker, $l, new CreditPricing('1', '3')), $r, $e, $l];
}

function aRepo(): FakeAnalystRepo
{
    $rows = [];
    foreach ([['2026-07', 100000, 50000], ['2026-08', 200000, 60000], ['2026-09', 300000, 70000], ['2026-10', 400000, 100000]] as [$ym, $i, $x]) {
        $rows[] = ['ym' => $ym, 'type' => 'income', 'account_id' => 'S', 'name' => 'Sales', 'amount_minor' => $i];
        $rows[] = ['ym' => $ym, 'type' => 'expense', 'account_id' => 'R', 'name' => 'ভাড়া', 'amount_minor' => $x];
    }

    return new FakeAnalystRepo($rows, [], 5000000, 70000, 30000);
}

function aFacts(): array
{
    $r = aRepo();
    $f = Facts::build('2026-10', $r->rows, [], $r->cashEnd, $r->recv, $r->pay);

    return [$f, Forecast::build($f)];
}

it('finds the newest complete month', function () {
    expect(AnalystService::lastFullMonth('2026-11-04'))->toBe('2026-10');
    expect(AnalystService::lastFullMonth('2027-01-01'))->toBe('2026-12');
});

it('writes the fixed Bengali template without calling or charging the model when AI is off', function () {
    [$s, $r, $e, $l] = asvc();
    $res = $s->analyze(AT, AU, null, false, ATODAY, AR);
    expect($res)->toMatchArray(['status' => 'ok', 'report_id' => AR, 'source' => 'template', 'note' => null]);
    expect($e->calls)->toBe(0);
    expect($l->log)->toHaveCount(0);
    $rep = $r->reports[AR];
    expect($rep['month'])->toBe('2026-10');
    expect($rep['narrative'])->toContain('October');
    expect($rep['narrative'])->toContain('estimated');
    expect($rep['model'])->toBeNull();
});

it('uses the AI wording only when every number is one the books gave it, then charges once', function () {
    [$f, $fc] = aFacts();
    $good = 'অক্টোবর মাসে আয় '.AnalystText::taka(400000).' আর খরচ '.AnalystText::taka(100000).', লাভ '.AnalystText::taka(300000).'। আনুমানিক ধরলে আগামী মাসে আয় '.AnalystText::taka($fc['months'][0]['income_minor']).' হতে পারে, তবে এটি নিশ্চিত নয়।';
    [$s, $r, $e, $l] = asvc(null, new FakeAnalystEngine(['text' => $good, 'input_tokens' => 1000, 'output_tokens' => 200, 'model' => 'gpt-test']));
    $res = $s->analyze(AT, AU, null, true, ATODAY, AR);
    expect($res)->toMatchArray(['status' => 'ok', 'source' => 'ai', 'note' => null]);
    expect($r->reports[AR]['narrative'])->toBe($good);
    expect($r->reports[AR]['model'])->toBe('gpt-test');
    expect($l->log)->toHaveCount(2);
    expect($l->log[1])->toBe('settle:'.(new CreditPricing('1', '3'))->cost(1000, 200, 1.0));
    expect($l->openReservations())->toBe(0);
    expect($r->reports[AR]['in'])->toBe(1000);
    expect($l->usages)->toHaveCount(1);
    expect($l->usages[0])->toMatchArray(['source' => 'analyst', 'user_id' => AU, 'request_id' => AR, 'input_tokens' => 1000, 'output_tokens' => 200]);
});

it('falls back to the template, still charging, when the AI quotes a number it was not given', function () {
    [$s, $r, $e, $l] = asvc(null, new FakeAnalystEngine(['text' => 'অক্টোবরে আয় হয়েছে 987,654 ৳ — অনেক ভালো মাস, আনুমানিক বৃদ্ধি চলবে।', 'input_tokens' => 1000, 'output_tokens' => 200, 'model' => 'gpt-test']));
    $res = $s->analyze(AT, AU, null, true, ATODAY, AR);
    expect($res)->toMatchArray(['status' => 'ok', 'source' => 'template', 'note' => 'rejected_unknown_number']);
    expect(str_contains($r->reports[AR]['narrative'], '987'))->toBeFalse();
    expect($l->log)->toHaveCount(2);
    expect($l->openReservations())->toBe(0);
    expect($r->reports[AR]['model'])->toBeNull();                       // the shown text is the template, not the model's
    expect($r->reports[AR]['in'])->toBe(1000);                          // but the real usage is recorded
});

it('rejects a forecast that is not called an estimate', function () {
    [$s, $r, $e, $l] = asvc(null, new FakeAnalystEngine(['text' => 'অক্টোবরে আয় ভালো ছিল এবং আগামী মাসগুলোতেও বাড়বে বলে মনে হচ্ছে, খরচের দিকে নজর রাখুন।', 'input_tokens' => 10, 'output_tokens' => 10, 'model' => 'm']));
    expect($s->analyze(AT, AU, null, true, ATODAY, AR)['note'])->toBe('rejected_unmarked_estimate');
});

it('releases the hold and uses the template when the provider fails', function () {
    [$s, $r, $e, $l] = asvc(null, new FakeAnalystEngine(ProviderDown::make()));
    $res = $s->analyze(AT, AU, null, true, ATODAY, AR);
    expect($res)->toMatchArray(['status' => 'ok', 'source' => 'template', 'note' => 'provider']);
    expect($l->log)->toBe(['reserve:'.(new CreditPricing('1', '3'))->reserveAmount(2500, 900, 1.0), 'release']);
    expect($l->openReservations())->toBe(0);
    expect($l->releaseKeys)->toBe(['analyst-release:'.AR]);
});

it('releases the hold when the engine blows up unexpectedly', function () {
    [$s, $r, $e, $l] = asvc(null, new FakeAnalystEngine(new RuntimeException('boom')));
    expect($s->analyze(AT, AU, null, true, ATODAY, AR)['note'])->toBe('engine');
    expect($l->openReservations())->toBe(0);
});

it('uses the template when there is no model or not enough credits, without any model call', function () {
    [$s, $r, $e, $l] = asvc(null, null, null, new FakeAnalystPicker(null));
    expect($s->analyze(AT, AU, null, true, ATODAY, AR)['note'])->toBe('no_model');
    [$s2, $r2, $e2, $l2] = asvc(null, null, new FakeLedger('0.0000'));
    expect($s2->analyze(AT, AU, null, true, ATODAY, AR)['note'])->toBe('no_credits');
    expect($e2->calls)->toBe(0);
    expect($r2->reports[AR]['source'])->toBe('template');
});

it('keeps the report even if settling the charge fails (the hold is returned later)', function () {
    [$f, $fc] = aFacts();
    $good = 'আয় '.AnalystText::taka(400000).', আনুমানিক পূর্বাভাস নিশ্চিত নয়, শুধু ট্রেন্ড।';
    $l = new FakeLedger('100.0000');
    $l->failSettle = true;
    [$s, $r] = asvc(null, new FakeAnalystEngine(['text' => $good, 'input_tokens' => 10, 'output_tokens' => 10, 'model' => 'm']), $l);
    expect($s->analyze(AT, AU, null, true, ATODAY, AR)['source'])->toBe('ai');
    expect($r->reports[AR]['credits'])->toBe('0.0000');
});

it('refuses a running or future month, a bad month, and an empty ledger', function () {
    [$s, $r, $e, $l] = asvc();
    expect($s->analyze(AT, AU, '2026-11', false, ATODAY, AR)['status'])->toBe('bad_month');
    expect($s->analyze(AT, AU, '2027-01', false, ATODAY, AR)['status'])->toBe('bad_month');
    expect($s->analyze(AT, AU, '2026-13', false, ATODAY, AR)['status'])->toBe('bad_month');
    expect($s->analyze(AT, AU, 'oct', false, ATODAY, AR)['status'])->toBe('bad_month');
    expect($s->analyze(AT, AU, '2026-10', false, ATODAY, AR)['status'])->toBe('ok');
    [$s2] = asvc(new FakeAnalystRepo);
    expect($s2->analyze(AT, AU, null, true, ATODAY, AR)['status'])->toBe('no_data');
    expect($r->reports)->toHaveCount(1);
});

it('reports a failed save', function () {
    $r = aRepo();
    $r->saveResult = 'bad_input';
    [$s] = asvc($r);
    expect($s->analyze(AT, AU, null, false, ATODAY, AR))->toMatchArray(['status' => 'failed', 'note' => 'bad_input']);
});

it('puts the facts, not instructions, in the prompt and tells the model about estimates', function () {
    [$f, $fc] = aFacts();
    $m = AnalystText::messages(AnalystText::factSheet($f, $fc), true);
    expect($m[0]['role'])->toBe('system');
    expect($m[0]['content'])->toContain('estimated');
    expect($m[1]['content'])->toContain('Total income: 4,000 ৳');
    $none = AnalystText::messages('x', false);
    expect($none[0]['content'])->toContain('পূর্বাভাস দিও না');
});

it('checks numbers in either digit script, with or without thousands commas', function () {
    [$f, $fc] = aFacts();
    expect(NarrativeCheck::verify('আয় ৪,০০০ ৳ আর খরচ ১,০০০ ৳, আনুমানিক ধারা নিশ্চিত নয়।', $f, $fc))->toBe('ok');
    expect(NarrativeCheck::verify('আয় 4,001 ৳ আর খরচ ১,০০০ ৳, আনুমানিক ধারা নিশ্চিত নয়।', $f, $fc))->toBe('unknown_number');
    expect(NarrativeCheck::verify('Small', $f, $fc))->toBe('empty');
    expect(NarrativeCheck::verify(str_repeat('ক', 6001), $f, $fc))->toBe('empty');
    expect(NarrativeCheck::verify('গত ৩ মাসে ২০২৬ সালে আয় ঠিকই আছে, আনুমানিক বলা যায় না তবু।', $f, $fc))->toBe('ok');
    expect(NarrativeCheck::verify('গত 13 মাসে আয় ঠিকই আছে, আনুমানিক বলা যায় না তবু।', $f, $fc))->toBe('unknown_number');
});

it('accepts quoted percentages, a loss written as a positive amount, and a fractional runway', function () {
    $cash = array_map(fn ($ym) => ['ym' => $ym, 'amount_minor' => -300000], ['2026-08', '2026-09', '2026-10']);
    $rows = [];
    foreach (['2026-08' => [100000, 200000], '2026-09' => [100000, 200000], '2026-10' => [120000, 300000]] as $ym => [$i, $x]) {
        $rows[] = ['ym' => $ym, 'type' => 'income', 'account_id' => 'S', 'name' => 'Sales', 'amount_minor' => $i];
        $rows[] = ['ym' => $ym, 'type' => 'expense', 'account_id' => 'R', 'name' => 'ভাড়া', 'amount_minor' => $x];
    }
    $f = Facts::build('2026-10', $rows, $cash, 1000000, 0, 0);
    $fc = Forecast::build($f);
    expect($f['income_change_pct'])->toBe(20.0);
    expect(NarrativeCheck::verify('আয় আগের মাসের তুলনায় 20% বেড়েছে, আর ক্ষতি হয়েছে 1,800 ৳ — আনুমানিক ধরলে নগদ কমছেই।', $f, $fc))->toBe('ok');
    expect(NarrativeCheck::verify('আয় 20.5% বেড়েছে — আনুমানিক ধরলে নগদ কমছেই, নিশ্চিত নয়।', $f, $fc))->toBe('unknown_number');
    expect($fc['runway_months'])->toBe(3.3);
    expect(NarrativeCheck::verify('নগদ আনুমানিক 3.3 মাস চলবে, এটি নিশ্চিত নয় — সতর্ক থাকুন।', $f, $fc))->toBe('ok');
});

it('only demands the word "estimate" when a forecast exists', function () {
    $f = Facts::build('2026-10', [['ym' => '2026-10', 'type' => 'income', 'account_id' => 'S', 'name' => 'Sales', 'amount_minor' => 100000]], [], 0, 0, 0);
    $fc = Forecast::build($f);
    expect($fc['enough'])->toBeFalse();
    expect(NarrativeCheck::verify('অক্টোবরে আয় 1,000 ৳ হয়েছে, পূর্বাভাসের মতো যথেষ্ট তথ্য নেই।', $f, $fc))->toBe('ok');
});

it('has a template for thin history and for a loss streak', function () {
    $f = Facts::build('2026-10', [['ym' => '2026-10', 'type' => 'expense', 'account_id' => 'R', 'name' => 'ভাড়া', 'amount_minor' => 5000]], [], 0, 0, 0);
    $t = AnalystText::template($f, Forecast::build($f));
    expect($t)->toContain('loss 50 ৳');
    expect($t)->toContain('Forecast: At least');
    $rows = [];
    foreach (['2026-08', '2026-09', '2026-10'] as $ym) {
        $rows[] = ['ym' => $ym, 'type' => 'expense', 'account_id' => 'R', 'name' => 'ভাড়া', 'amount_minor' => 5000];
    }
    $g = Facts::build('2026-10', $rows, [], 100, 0, 0);
    expect(AnalystText::template($g, Forecast::build($g)))->toContain('টানা 3 মাস ক্ষতি');
});
