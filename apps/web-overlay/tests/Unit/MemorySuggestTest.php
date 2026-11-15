<?php

use App\Chat\CreditPricing;
use App\Memory\MemorySuggestService;
use Tests\Support\FakeLedger;
use Tests\Support\FakeMemoryEngine;
use Tests\Support\FakeMemoryRepo;
use Tests\Support\FakePicker;
use Tests\Support\FakeStore;
use Tests\Support\ProviderDown;

const MT = 'TENANT0000000000000000000A';
const MU = 'USER000000000000000000000A';
const MIDS = ['i1', 'i2', 'i3', 'i4', 'i5'];

function msvc(?FakeMemoryEngine $e = null, ?FakeLedger $l = null, ?FakePicker $p = null, ?FakeMemoryRepo $r = null, ?FakeStore $s = null): array
{
    $s ??= new FakeStore;
    $c = $s->createConversation(MT, MU, 'chat', null);
    $s->addUserMessage(MT, MU, $c, 'আমি ঢাকায় থাকি, ছোট উত্তর দিও', []);
    $e ??= new FakeMemoryEngine;
    $l ??= new FakeLedger('100.0000');
    $r ??= new FakeMemoryRepo;

    return [new MemorySuggestService($s, $p ?? new FakePicker, $e, $r, $l, new CreditPricing('1', '3')), $c, $e, $l, $r, $s];
}

it('stores what the model proposes as suggestions only and charges the real cost once', function () {
    $e = new FakeMemoryEngine(['text' => '[{"content":"Lives in Dhaka","category":"fact"},{"content":"Prefers short answers","category":"preference"}]', 'input_tokens' => 800, 'output_tokens' => 60, 'model' => 'm']);
    [$s, $c, $e, $l, $r] = msvc($e);
    expect($s->suggest(MT, MU, $c, MIDS, 'req1'))->toBe(['status' => 'ok', 'count' => 2]);
    expect($r->saved)->toHaveCount(1);
    expect($r->saved[0])->toMatchArray(['conversation' => $c, 'user' => MU, 'ids' => MIDS]);
    expect(array_column($r->saved[0]['items'], 'content'))->toBe(['Lives in Dhaka', 'Prefers short answers']);
    expect($l->log)->toHaveCount(2);
    expect($l->log[1])->toBe('settle:'.(new CreditPricing('1', '3'))->cost(800, 60, 1.0));
    expect($l->openReservations())->toBe(0);
    expect($l->usages[0])->toMatchArray(['source' => 'memory', 'user_id' => MU, 'request_id' => 'req1']);
    expect($e->seen[0][1]['content'])->toContain('User: আমি ঢাকায় থাকি');
});

it('refuses a conversation that is not this person\'s, before any model call or charge', function () {
    [$s, $c, $e, $l] = msvc();
    expect($s->suggest(MT, 'OTHERUSER000000000000000000', $c, MIDS, 'r'))->toBe(['status' => 'not_found', 'count' => 0]);
    expect($s->suggest(MT, MU, 'NOSUCHCHAT0000000000000000', MIDS, 'r')['status'])->toBe('not_found');
    expect($e->calls)->toBe(0);
    expect($l->log)->toHaveCount(0);
});

it('has nothing to read in an empty or assistant-only conversation', function () {
    $st = new FakeStore;
    $c = $st->createConversation(MT, MU, 'empty', null);
    $svc = new MemorySuggestService($st, new FakePicker, $e = new FakeMemoryEngine, new FakeMemoryRepo, $l = new FakeLedger('100.0000'), new CreditPricing('1', '3'));
    expect($svc->suggest(MT, MU, $c, MIDS, 'r')['status'])->toBe('nothing');
    expect($e->calls)->toBe(0);
    expect($l->log)->toHaveCount(0);
});

it('stops cleanly without a model or without credits', function () {
    [$s, $c, $e, $l] = msvc(null, null, new FakePicker(null));
    expect($s->suggest(MT, MU, $c, MIDS, 'r')['status'])->toBe('no_model');
    [$s2, $c2, $e2, $l2] = msvc(null, new FakeLedger('0.0000'));
    expect($s2->suggest(MT, MU, $c2, MIDS, 'r')['status'])->toBe('no_credits');
    expect($e->calls + $e2->calls)->toBe(0);
});

it('releases the hold on a provider failure or crash and saves nothing', function () {
    [$s, $c, $e, $l, $r] = msvc(new FakeMemoryEngine(ProviderDown::make()));
    expect($s->suggest(MT, MU, $c, MIDS, 'r')['status'])->toBe('provider');
    expect($l->releaseKeys)->toBe(['memory-release:r']);
    expect($l->openReservations())->toBe(0);
    expect($r->saved)->toBe([]);
    [$s2, $c2, $e2, $l2] = msvc(new FakeMemoryEngine(new RuntimeException('boom')));
    expect($s2->suggest(MT, MU, $c2, MIDS, 'r')['status'])->toBe('failed');
    expect($l2->openReservations())->toBe(0);
});

it('charges but saves nothing when the answer is unreadable, and reports nothing found for an empty list', function () {
    [$s, $c, $e, $l, $r] = msvc(new FakeMemoryEngine(['text' => 'I cannot do that', 'input_tokens' => 10, 'output_tokens' => 10, 'model' => 'm']));
    expect($s->suggest(MT, MU, $c, MIDS, 'r')['status'])->toBe('unreadable');
    expect($l->log)->toHaveCount(2);
    expect($r->saved)->toBe([]);
    [$s2, $c2, $e2, $l2, $r2] = msvc(new FakeMemoryEngine(['text' => '[]', 'input_tokens' => 10, 'output_tokens' => 2, 'model' => 'm']));
    expect($s2->suggest(MT, MU, $c2, MIDS, 'r')['status'])->toBe('nothing');
    expect($r2->saved)->toBe([]);
});

it('reports nothing when every proposal was filtered out by the database, and survives a failed settle', function () {
    $r = new FakeMemoryRepo;
    $r->storeResult = 0;
    [$s, $c] = msvc(new FakeMemoryEngine(['text' => '[{"content":"Lives in Dhaka","category":"fact"}]', 'input_tokens' => 10, 'output_tokens' => 10, 'model' => 'm']), null, null, $r);
    expect($s->suggest(MT, MU, $c, MIDS, 'r'))->toBe(['status' => 'nothing', 'count' => 0]);
    $l = new FakeLedger('100.0000');
    $l->failSettle = true;
    [$s2, $c2] = msvc(new FakeMemoryEngine(['text' => '[{"content":"Lives in Dhaka","category":"fact"}]', 'input_tokens' => 10, 'output_tokens' => 10, 'model' => 'm']), $l);
    expect($s2->suggest(MT, MU, $c2, MIDS, 'r')['status'])->toBe('ok');
});

it('reads only user and assistant turns, and only the newest 24', function () {
    $st = new FakeStore;
    [$s, $c, $e] = msvc(new FakeMemoryEngine(['text' => '[]', 'input_tokens' => 10, 'output_tokens' => 2, 'model' => 'm']), null, null, null, $st);
    for ($i = 1; $i <= 30; $i++) {
        $st->addUserMessage(MT, MU, $c, "turn-$i-end", []);
    }
    $st->messages['sys'] = ['conversation' => $c, 'role' => 'system', 'content' => 'SYSTEMNOISE', 'status' => 'complete', 'hidden' => false, 'attachments' => [], 'credits' => '0.0000', 'error' => null];
    $s->suggest(MT, MU, $c, MIDS, 'r');
    $sent = $e->seen[0][1]['content'];
    expect(str_contains($sent, 'SYSTEMNOISE'))->toBeFalse();
    expect(str_contains($sent, 'turn-30-end'))->toBeTrue();
    expect(str_contains($sent, 'turn-7-end'))->toBeTrue();
    expect(str_contains($sent, 'turn-6-end'))->toBeFalse();
});
