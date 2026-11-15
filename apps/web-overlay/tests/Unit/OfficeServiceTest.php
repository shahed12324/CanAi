<?php

use App\Chat\CreditPricing;
use App\Office\OfficeService;
use Tests\Support\FakeLedger;
use Tests\Support\FakeOfficeEngine;
use Tests\Support\FakeOfficeRepo;
use Tests\Support\FakePicker;
use Tests\Support\ProviderDown;

const OT = 'TENANT0000000000000000000A';
const OU = 'USER000000000000000000000A';
const OFID = 'FILE000000000000000000000A';

function osvc(?FakeOfficeEngine $e = null, ?FakeLedger $l = null, ?FakePicker $p = null, ?FakeOfficeRepo $r = null): array
{
    $e ??= new FakeOfficeEngine;
    $l ??= new FakeLedger('100.0000');
    $r ??= new FakeOfficeRepo;

    return [new OfficeService($p ?? new FakePicker, $e, $r, $l, new CreditPricing('1', '3')), $e, $l, $r];
}

it('writes the spec, saves it, and charges the real cost once', function () {
    [$s, $e, $l, $r] = osvc();
    expect($s->create(OT, OU, 'docx', 'মাসিক রিপোর্ট বানাও', OFID, 'req1'))->toBe(['status' => 'ok', 'id' => OFID, 'title' => 'রিপোর্ট']);
    expect($r->files[OFID])->toMatchArray(['user' => OU, 'kind' => 'docx', 'title' => 'রিপোর্ট', 'request' => 'মাসিক রিপোর্ট বানাও']);
    expect($r->files[OFID]['spec']['blocks'][0])->toBe(['t' => 'p', 'text' => 'হ্যালো']);
    expect($l->log)->toHaveCount(2);
    expect($l->log[1])->toBe('settle:'.(new CreditPricing('1', '3'))->cost(900, 400, 1.0));
    expect($l->openReservations())->toBe(0);
    expect($l->usages[0])->toMatchArray(['source' => 'docgen', 'user_id' => OU, 'request_id' => 'req1']);
    expect($l->reserveKeys)->toBe(['docgen:req1']);
    expect($l->settleKeys)->toBe(['docgen-settle:req1']);
    expect($e->seen[0]['messages'][1]['content'])->toBe("Request:\nমাসিক রিপোর্ট বানাও");
    expect($e->seen[0]['max'])->toBe(1000);                      // the model's own output limit (FakePicker: 1000)
});

it('asks for at most 6000 tokens and at least 500', function () {
    $big = new FakePicker(['id' => 'm', 'provider_id' => 'p', 'model_key' => 'k', 'name' => 'n', 'max_output' => 100000, 'context_window' => 1, 'credit_multiplier' => 1.0]);
    [$s, $e] = osvc(null, null, $big);
    $s->create(OT, OU, 'docx', 'x', OFID, 'r');
    expect($e->seen[0]['max'])->toBe(6000);
    $tiny = new FakePicker(['id' => 'm', 'provider_id' => 'p', 'model_key' => 'k', 'name' => 'n', 'max_output' => 50, 'context_window' => 1, 'credit_multiplier' => 1.0]);
    [$s2, $e2] = osvc(null, null, $tiny);
    $s2->create(OT, OU, 'docx', 'x', OFID, 'r');
    expect($e2->seen[0]['max'])->toBe(500);
});

it('refuses an unknown kind or an empty request before any model call or charge', function () {
    [$s, $e, $l] = osvc();
    expect($s->create(OT, OU, 'pdf', 'x', OFID, 'r')['status'])->toBe('bad_kind');
    expect($s->create(OT, OU, 'docx', "  \x00 \n ", OFID, 'r')['status'])->toBe('empty_request');
    expect($e->calls)->toBe(0);
    expect($l->log)->toHaveCount(0);
});

it('stops at the 100-file limit before spending anything', function () {
    [$s, $e, $l, $r] = osvc();
    for ($i = 0; $i < 100; $i++) {
        $r->files["F$i"] = ['id' => "F$i", 'user' => OU, 'kind' => 'docx', 'title' => 't', 'request' => '', 'spec' => [], 'created_at' => 'n'];
    }
    expect($s->create(OT, OU, 'docx', 'x', OFID, 'r')['status'])->toBe('limit');
    expect($e->calls)->toBe(0);
    expect($l->log)->toHaveCount(0);
    unset($r->files['F0']);
    expect($s->create(OT, OU, 'docx', 'x', OFID, 'r')['status'])->toBe('ok');
});

it('stops cleanly without a model or without credits', function () {
    [$s, $e, $l] = osvc(null, null, new FakePicker(null));
    expect($s->create(OT, OU, 'docx', 'x', OFID, 'r')['status'])->toBe('no_model');
    [$s2, $e2] = osvc(null, new FakeLedger('0.0000'));
    expect($s2->create(OT, OU, 'docx', 'x', OFID, 'r')['status'])->toBe('no_credits');
    expect($e->calls + $e2->calls)->toBe(0);
});

it('releases the hold on a provider failure or crash and saves nothing', function () {
    [$s, $e, $l, $r] = osvc(new FakeOfficeEngine(ProviderDown::make()));
    expect($s->create(OT, OU, 'docx', 'x', OFID, 'r')['status'])->toBe('provider');
    expect($l->releaseKeys)->toBe(['docgen-release:r']);
    expect($l->openReservations())->toBe(0);
    expect($r->files)->toBe([]);
    [$s2, $e2, $l2] = osvc(new FakeOfficeEngine(new RuntimeException('boom')));
    expect($s2->create(OT, OU, 'docx', 'x', OFID, 'r')['status'])->toBe('failed');
    expect($l2->openReservations())->toBe(0);
});

it('charges but saves nothing when the answer is not a usable file', function () {
    [$s, $e, $l, $r] = osvc(new FakeOfficeEngine(['text' => '{"title":"x","blocks":[', 'input_tokens' => 10, 'output_tokens' => 10, 'model' => 'm']));
    expect($s->create(OT, OU, 'docx', 'x', OFID, 'r')['status'])->toBe('unreadable');
    expect($l->log)->toHaveCount(2);
    expect($l->openReservations())->toBe(0);
    expect($r->files)->toBe([]);
});

it('reports a database refusal after the run and survives a failed settle', function () {
    $r = new FakeOfficeRepo;
    $r->saveResult = 'limit';
    [$s] = osvc(null, null, null, $r);
    expect($s->create(OT, OU, 'docx', 'x', OFID, 'r')['status'])->toBe('limit');
    $r->saveResult = 'bad_input';
    expect($s->create(OT, OU, 'docx', 'x', OFID, 'r2')['status'])->toBe('failed');
    $l = new FakeLedger('100.0000');
    $l->failSettle = true;
    [$s2] = osvc(null, $l);
    expect($s2->create(OT, OU, 'docx', 'x', OFID, 'r')['status'])->toBe('ok');
});

it('builds the file from the saved spec, only for its owner, with a safe name', function () {
    [$s, $e, $l, $r] = osvc(new FakeOfficeEngine(['text' => '{"title":"রিপোর্ট","sheets":[{"name":"A","headers":["a"],"rows":[[1]]}]}', 'input_tokens' => 10, 'output_tokens' => 10, 'model' => 'm']));
    $s->create(OT, OU, 'xlsx', 'x', OFID, 'r');
    $l->log = [];
    $d = $s->download(OT, OU, OFID);
    expect($d)->toBe(['bytes' => 'FILE:xlsx', 'name' => 'রিপোর্ট.xlsx', 'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    expect($e->built[0]['kind'])->toBe('xlsx');
    expect($l->log)->toHaveCount(0);                                  // downloading again costs nothing
    expect($s->download(OT, 'OTHERUSER000000000000000000', OFID))->toBeNull();
    expect($s->download(OT, OU, 'NOSUCHFILE00000000000000000'))->toBeNull();
    expect($e->builds)->toBe(1);
});

it('lets an engine failure on download surface', function () {
    [$s, $e] = osvc();
    $s->create(OT, OU, 'docx', 'x', OFID, 'r');
    $e->buildFails = true;
    $threw = false;
    try {
        $s->download(OT, OU, OFID);
    } catch (RuntimeException) {
        $threw = true;
    }
    expect($threw)->toBeTrue();
});

it('refuses to build a stored file of an unknown kind', function () {
    [$s, $e, $l, $r] = osvc();
    $r->files['X'] = ['id' => 'X', 'user' => OU, 'kind' => 'exe', 'title' => 't', 'request' => '', 'spec' => [], 'created_at' => 'n'];
    expect($s->download(OT, OU, 'X'))->toBeNull();
    expect($e->builds)->toBe(0);
});

it('uses the estimate when the provider reports no token counts, and cuts a long request to the stored limit', function () {
    [$s, $e, $l, $r] = osvc(new FakeOfficeEngine(['text' => '{"title":"রিপোর্ট","blocks":[{"t":"p","text":"x"}]}', 'input_tokens' => null, 'output_tokens' => null, 'model' => 'm']));
    expect($s->create(OT, OU, 'docx', str_repeat('ক', 2500), OFID, 'r')['status'])->toBe('ok');
    expect($l->log[1])->toBe('settle:'.(new CreditPricing('1', '3'))->cost(1500, 0, 1.0));
    expect(mb_strlen($r->files[OFID]['request']))->toBe(2000);
});
