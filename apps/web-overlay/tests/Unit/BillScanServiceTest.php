<?php

use App\BillScan\BillScanService;
use App\Chat\CreditPricing;
use Tests\Support\FakeBillEngine;
use Tests\Support\FakeBillFiles;
use Tests\Support\FakeBillRepo;
use Tests\Support\FakeLedger;
use Tests\Support\FakeVision;
use Tests\Support\ProviderDown;

const BT = 'TENANT0000000000000000000A';
const BU = 'USER000000000000000000000A';
const BJPG = "\xFF\xD8\xFF\xE0".'bill-bytes';
const BACC = [['code' => '5100', 'name' => 'Office']];

function bsvc(?FakeBillEngine $e = null, ?FakeLedger $l = null, ?FakeBillRepo $r = null, ?FakeBillFiles $f = null, ?FakeVision $v = null): array
{
    $r ??= new FakeBillRepo;
    $e ??= new FakeBillEngine;
    $l ??= new FakeLedger('100.0000');
    $f ??= new FakeBillFiles;

    return [new BillScanService($r, $e, $f, $v ?? new FakeVision, $l, new CreditPricing('1', '3')), $r, $e, $l, $f];
}

it('reads a bill, charges the real cost once and leaves a ready draft', function () {
    [$s, $r, $e, $l, $f] = bsvc();
    $res = $s->scan(BT, BU, 'bill.jpg', BJPG, BACC);
    expect($res['status'])->toBe('ready');
    expect($r->scans[$res['scan_id']]['status'])->toBe('ready');
    expect($l->log)->toHaveCount(2);
    expect($l->log[1])->toBe('settle:'.(new CreditPricing('1', '3'))->cost(1500, 300, 1.0));
    expect($l->openReservations())->toBe(0);
    expect($e->seen[0]['accounts'])->toBe(BACC);
    expect($f->files)->toHaveCount(1);
});

it('does not charge or call the model again for the same file', function () {
    [$s, $r, $e, $l] = bsvc();
    $a = $s->scan(BT, BU, 'bill.jpg', BJPG, BACC);
    $b = $s->scan(BT, BU, 'copy.jpg', BJPG, BACC);
    expect($b)->toMatchArray(['status' => 'duplicate', 'scan_id' => $a['scan_id']]);
    expect($e->calls)->toBe(1);
    expect($l->log)->toHaveCount(2);
});

it('rejects non-bill files before anything is stored or charged', function () {
    [$s, $r, $e, $l] = bsvc();
    $res = $s->scan(BT, BU, 'x.exe', 'MZ......', BACC);
    expect($res['status'])->toBe('rejected');
    expect($r->scans)->toHaveCount(0);
    expect($l->log)->toHaveCount(0);
});

it('fails cleanly without a vision model, charging nothing', function () {
    [$s, $r, $e, $l] = bsvc(null, null, null, null, new FakeVision(null));
    $res = $s->scan(BT, BU, 'b.jpg', BJPG, BACC);
    expect($res)->toMatchArray(['status' => 'failed', 'error' => 'no_model']);
    expect($l->log)->toHaveCount(0);
    expect($e->calls)->toBe(0);
});

it('stops on insufficient credits before the model is called', function () {
    [$s, $r, $e, $l] = bsvc(null, new FakeLedger('0.0001'));
    $res = $s->scan(BT, BU, 'b.jpg', BJPG, BACC);
    expect($res)->toMatchArray(['status' => 'failed', 'error' => 'insufficient_credits']);
    expect($e->calls)->toBe(0);
    expect($r->scans[$res['scan_id']]['status'])->toBe('failed');
});

it('releases the hold when the provider fails, and the file can be tried again', function () {
    [$s, $r, $e, $l, $f] = bsvc(new FakeBillEngine(ProviderDown::make()));
    $res = $s->scan(BT, BU, 'b.jpg', BJPG, BACC);
    expect($res)->toMatchArray(['status' => 'failed', 'error' => 'provider:rate_limited']);
    expect($l->log[1])->toBe('release');
    expect($l->openReservations())->toBe(0);
    expect($l->balance)->toBe('100.0000');
    expect($f->files)->toHaveCount(0);
    $e->answer = (new FakeBillEngine)->answer;
    expect($s->scan(BT, BU, 'b.jpg', BJPG, BACC)['status'])->toBe('ready');
});

it('releases the hold on an unexpected engine error or a storage error', function () {
    [$s, $r, $e, $l] = bsvc(new FakeBillEngine(new RuntimeException('boom')));
    expect($s->scan(BT, BU, 'b.jpg', BJPG, BACC)['error'])->toBe('engine_error');
    expect($l->openReservations())->toBe(0);
    $f = new FakeBillFiles;
    $f->failPut = true;
    [$s2, , $e2, $l2] = bsvc(null, null, null, $f);
    expect($s2->scan(BT, BU, 'b.jpg', BJPG, BACC)['status'])->toBe('failed');
    expect($e2->calls)->toBe(0);
    expect($l2->openReservations())->toBe(0);
});

it('releases for an unsupported file (the engine refused it before calling any model)', function () {
    [$s, $r, $e, $l] = bsvc(new FakeBillEngine(['status' => 'unsupported', 'reason' => 'encrypted_pdf']));
    expect($s->scan(BT, BU, 'b.jpg', BJPG, BACC)['error'])->toBe('unsupported:encrypted_pdf');
    expect($l->log[1])->toBe('release');
});

it('charges when the model was paid but could not read the bill', function () {
    [$s, $r, $e, $l, $f] = bsvc(new FakeBillEngine(['status' => 'unreadable', 'input_tokens' => 2000, 'output_tokens' => 50]));
    $res = $s->scan(BT, BU, 'b.jpg', BJPG, BACC);
    expect($res)->toMatchArray(['status' => 'failed', 'error' => 'unreadable']);
    expect($l->log[1])->toStartWith('settle:');
    expect($r->scans[$res['scan_id']]['in'])->toBe(2000);
    expect($f->files)->toHaveCount(0);
    expect($l->openReservations())->toBe(0);
});

it('settles but fails the scan when the draft is unusable', function () {
    [$s, $r, $e, $l] = bsvc(new FakeBillEngine(['status' => 'ok', 'data' => ['lines' => []], 'flags' => [], 'input_tokens' => 100, 'output_tokens' => 10]));
    $res = $s->scan(BT, BU, 'b.jpg', BJPG, BACC);
    expect($res['error'])->toBe('bad_draft');
    expect($l->log[1])->toStartWith('settle:');
});

it('never charges the customer when settle itself breaks (the hold is returned by the stale sweep)', function () {
    $l = new FakeLedger('100.0000');
    $l->failSettle = true;
    [$s, $r] = bsvc(null, $l);
    $res = $s->scan(BT, BU, 'b.jpg', BJPG, BACC);
    expect($res['status'])->toBe('ready');
    expect($l->balance)->toBe('100.0000');
});

it('still reports a failed scan when even the failure write breaks', function () {
    $r = new FakeBillRepo;
    $r->failFail = true;
    [$s, , , $l] = bsvc(new FakeBillEngine(ProviderDown::make()), null, $r);
    expect($s->scan(BT, BU, 'b.jpg', BJPG, BACC)['status'])->toBe('failed');
    expect($l->openReservations())->toBe(0);
});

it('caps the accounts it offers the model', function () {
    [$s, $r, $e] = bsvc();
    $many = array_map(fn ($i) => ['code' => (string) (1000 + $i), 'name' => "A$i"], range(1, 200));
    $s->scan(BT, BU, 'b.jpg', BJPG, $many);
    expect($e->seen[0]['accounts'])->toHaveCount(80);
});
