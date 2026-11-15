<?php

use App\Chat\CreditPricing;
use App\Pc\PcPlanner;
use Tests\Support\FakeLedger;
use Tests\Support\FakePcPlanRepo;
use Tests\Support\FakePcRepo;
use Tests\Support\FakePicker;
use Tests\Support\FakePlanEngine;
use Tests\Support\ProviderDown;

const PT = 'TENANT0000000000000000000A';
const PU = 'USER000000000000000000000A';
const PD = 'DEVICE000000000000000000A1';

function psvc(?FakePlanEngine $e = null, ?FakeLedger $l = null, ?FakePicker $p = null, ?FakePcPlanRepo $r = null, ?array $policy = ['roots' => ['/home/me/work'], 'apps' => [], 'commands' => [], 'hosts' => [], 'modes' => []]): array
{
    $e ??= new FakePlanEngine;
    $l ??= new FakeLedger('100.0000');
    $r ??= new FakePcPlanRepo;
    $d = new FakePcRepo;
    $d->policyRow = $policy;

    return [new PcPlanner($p ?? new FakePicker, $e, $d, $r, $l, new CreditPricing('1', '3')), $e, $l, $r];
}

it('drafts a plan, submits it through the normal path and charges the real cost once', function () {
    [$s, $e, $l, $r] = psvc();
    $res = $s->draft(PT, PU, PD, 'make a folder', 'req1');
    expect($res['status'])->toBe('ok');
    expect($r->submitted[0]['goal'])->toBe('make a folder');
    expect($r->submitted[0]['plan']['steps'][0]['kind'])->toBe('make_dir');
    expect($l->log[1])->toBe('settle:'.(new CreditPricing('1', '3'))->cost(800, 200, 1.0));
    expect($l->openReservations())->toBe(0);
    expect($l->reserveKeys)->toBe(['pcplan:req1']);
    expect($e->seen[0]['roots'])->toBe(['/home/me/work']);
});

it('refuses a bad goal or a device without folders before any model call or charge', function () {
    [$s, $e, $l] = psvc();
    expect($s->draft(PT, PU, PD, "  \x00 ", 'r')['status'])->toBe('bad_goal');
    expect($s->draft(PT, PU, PD, str_repeat('x', 2001), 'r')['status'])->toBe('bad_goal');
    [$s2, $e2, $l2] = psvc(null, null, null, null, null);
    expect($s2->draft(PT, PU, PD, 'do it', 'r')['status'])->toBe('no_policy');
    [$s3, $e3, $l3] = psvc(null, null, null, null, ['roots' => [], 'apps' => [], 'commands' => [], 'hosts' => [], 'modes' => []]);
    expect($s3->draft(PT, PU, PD, 'do it', 'r')['status'])->toBe('no_policy');
    expect($e->calls + $e2->calls + $e3->calls)->toBe(0);
    expect(count($l->log) + count($l2->log) + count($l3->log))->toBe(0);
});

it('stops without a model or without credits, spending nothing', function () {
    [$s, $e, $l] = psvc(null, null, new FakePicker(null));
    expect($s->draft(PT, PU, PD, 'do it', 'r')['status'])->toBe('no_model');
    [$s2, $e2, $l2] = psvc(null, new FakeLedger('0.0000'));
    expect($s2->draft(PT, PU, PD, 'do it', 'r')['status'])->toBe('no_credits');
    expect($e->calls + $e2->calls)->toBe(0);
});

it('releases the hold and charges nothing when the provider fails', function () {
    [$s, $e, $l, $r] = psvc(new FakePlanEngine(ProviderDown::make()));
    expect($s->draft(PT, PU, PD, 'do it', 'r')['status'])->toBe('provider');
    expect($l->openReservations())->toBe(0);
    expect($l->log)->toHaveCount(2);
    expect($r->submitted)->toBe([]);
});

it('charges when the model answered but the draft was refused or invalid, and submits nothing', function () {
    foreach ([['refused', 'refused'], ['invalid', 'invalid']] as [$st, $want]) {
        [$s, $e, $l, $r] = psvc(new FakePlanEngine(['status' => $st, 'reason' => 'x', 'input_tokens' => 500, 'output_tokens' => 100]));
        expect($s->draft(PT, PU, PD, 'do it', 'r')['status'])->toBe($want);
        expect($l->log[1])->toBe('settle:'.(new CreditPricing('1', '3'))->cost(500, 100, 1.0));
        expect($r->submitted)->toBe([]);
    }
});

it('reports the database rejecting a draft (still charged) and never claims success', function () {
    $repo = new FakePcPlanRepo;
    $repo->submitResult = ['status' => 'rejected', 'reason' => 'outside_scope:s1'];
    [$s, $e, $l] = psvc(null, null, null, $repo);
    $res = $s->draft(PT, PU, PD, 'do it', 'r');
    expect($res['status'])->toBe('rejected');
    expect($res['reason'])->toBe('outside_scope:s1');
    expect($l->openReservations())->toBe(0);
});

it('never offers kinds the device rules deny, and always allows verify_file', function () {
    [$s, $e] = psvc(null, null, null, null, ['roots' => ['/a'], 'apps' => [], 'commands' => [], 'hosts' => [], 'modes' => ['write_file' => 'deny', 'copy_file' => 'ask']]);
    $s->draft(PT, PU, PD, 'do it', 'r');
    expect(in_array('write_file', $e->seen[0]['kinds'], true))->toBeFalse();
    expect(in_array('copy_file', $e->seen[0]['kinds'], true))->toBeTrue();
    expect(in_array('verify_file', $e->seen[0]['kinds'], true))->toBeTrue();
    expect($e->seen[0]['apps'])->toBe([])->and($e->seen[0]['hosts'])->toBe([]);
});

it('passes the allowed apps and websites to the engine so screen and browser steps can be planned inside them', function () {
    [$s, $e] = psvc(null, null, null, null, ['roots' => ['/a'], 'apps' => ['notepad'], 'commands' => [], 'hosts' => ['example.com'], 'modes' => []]);
    $s->draft(PT, PU, PD, 'do it', 'r');
    expect($e->seen[0]['apps'])->toBe(['notepad'])->and($e->seen[0]['hosts'])->toBe(['example.com']);
    expect(in_array('make_report', $e->seen[0]['kinds'], true))->toBeTrue();
});

it('an unusable engine answer (no steps) is invalid and submits nothing', function () {
    [$s, $e, $l, $r] = psvc(new FakePlanEngine(['status' => 'ok', 'plan' => ['nope' => 1], 'input_tokens' => 1, 'output_tokens' => 1]));
    expect($s->draft(PT, PU, PD, 'do it', 'r')['status'])->toBe('invalid');
    expect($r->submitted)->toBe([]);
});
