<?php

use App\Ai\ProviderCallException;
use App\Chat\ChatRequest;
use App\Chat\ChatService;
use App\Chat\CreditPricing;
use Tests\Support\FakeEngine;
use Tests\Support\FakeLedger;
use Tests\Support\FakePicker;
use Tests\Support\FakeStore;

const T = 'TENANT0000000000000000000A';
const U = 'USER000000000000000000000A';

function chat(FakeEngine $engine, ?FakeLedger $ledger = null, ?FakeStore $store = null, ?FakePicker $picker = null): array
{
    $ledger ??= new FakeLedger('100.0000');
    $store ??= new FakeStore;
    $svc = new ChatService($picker ?? new FakePicker, $store, $engine, $ledger, new CreditPricing('1', '3'));

    return [$svc, $ledger, $store];
}

/** @return list<array<string, mixed>> */
function run(ChatService $svc, ChatRequest $r, ?callable $stop = null): array
{
    return iterator_to_array($svc->send($r, $stop), false);
}

function req(?string $text = 'hello', ?string $conv = null): ChatRequest
{
    return new ChatRequest(T, U, $conv, $text);
}

function ev(string $text, ?int $in = null, ?int $out = null): array
{
    return ['text' => $text, 'input_tokens' => $in, 'output_tokens' => $out];
}

it('streams an answer, settles the real cost and keeps no reservation open', function () {
    [$svc, $ledger, $store] = chat(new FakeEngine([ev('Hel'), ev('lo!', 20, 100)]));
    $events = run($svc, req());

    expect(array_column($events, 'type'))->toBe(['meta', 'delta', 'delta', 'done']);
    $done = end($events);
    // 20 in x 1/1k + 100 out x 3/1k = 0.02 + 0.3 = 0.32 credits
    expect($done['credits'])->toBe('0.3200')
        ->and($ledger->balance)->toBe('99.6800')
        ->and($ledger->openReservations())->toBe(0)
        ->and($store->lastAssistant()['status'])->toBe('complete')
        ->and($store->lastAssistant()['content'])->toBe('Hello!');
});

it('creates the conversation with a title from the first message', function () {
    [$svc, , $store] = chat(new FakeEngine([ev('ok', 5, 5)]));
    $events = run($svc, req("  What   is\nlaravel?  "));

    expect($events[0]['title'])->toBe('What is laravel?')
        ->and(array_values($store->conversations)[0]['title'])->toBe('What is laravel?');
});

it('refuses without credits, charges nothing and never calls the AI', function () {
    $engine = new FakeEngine([ev('should not run')]);
    [$svc, $ledger, $store] = chat($engine, new FakeLedger('0.0000'));
    $events = run($svc, req());

    expect(end($events)['type'])->toBe('error')
        ->and(end($events)['code'])->toBe('insufficient_credits')
        ->and($engine->calls)->toBe(0)
        ->and($ledger->balance)->toBe('0.0000')
        ->and($store->lastAssistant()['status'])->toBe('error');
});

it('releases the whole hold when the provider fails before any output', function () {
    [$svc, $ledger, $store] = chat(new FakeEngine([new ProviderCallException('rate_limited', 'slow down', 429)]));
    $events = run($svc, req());

    expect(end($events)['code'])->toBe('rate_limited')
        ->and($ledger->balance)->toBe('100.0000')
        ->and($ledger->openReservations())->toBe(0)
        ->and($ledger->log)->toContain('release')
        ->and($store->lastAssistant()['status'])->toBe('error');
});

it('charges only for what was delivered when the provider dies mid-answer', function () {
    [$svc, $ledger, $store] = chat(new FakeEngine([ev('partial answer'), new ProviderCallException('timeout', 'cut off')]));
    $events = run($svc, req());

    $last = end($events);
    expect($last['type'])->toBe('error')->and($last['partial'])->toBeTrue()
        ->and($store->lastAssistant()['status'])->toBe('partial')
        ->and($store->lastAssistant()['content'])->toBe('partial answer')
        ->and($ledger->openReservations())->toBe(0)
        ->and((float) $ledger->balance)->toBeLessThan(100.0);
});

it('stops when the user presses stop, saves the partial text and still bills it', function () {
    [$svc, $ledger, $store] = chat(new FakeEngine([ev('one '), ev('two '), ev('three')]));
    $n = 0;
    $events = run($svc, req(), function () use (&$n) { return ++$n >= 2; });

    expect(implode('', array_column(array_filter($events, fn ($e) => $e['type'] === 'delta'), 'text')))->toBe('one two ')
        ->and(end($events)['stopped'])->toBeTrue()
        ->and($store->lastAssistant()['status'])->toBe('partial')
        ->and($ledger->openReservations())->toBe(0);
});

it('does not charge the customer when settling fails, and leaves a recoverable reservation', function () {
    $ledger = new FakeLedger('100.0000');
    $ledger->failSettle = true;
    [$svc, , $store] = chat(new FakeEngine([ev('fine', 5, 5)]), $ledger);
    $events = run($svc, req());

    expect(end($events)['type'])->toBe('done')->and(end($events)['credits'])->toBe('0.0000')
        ->and($store->lastAssistant()['status'])->toBe('complete')
        ->and($ledger->balance)->toBe('100.0000');   // stale-sweep gives the hold back
});

it('releases the hold if the consumer abandons the stream', function () {
    [$svc, $ledger, $store] = chat(new FakeEngine([ev('a'), ev('b'), ev('c')]));
    $gen = $svc->send(req());
    $gen->current();    // meta
    $gen->next();       // first delta
    unset($gen);        // client vanished; generator destroyed

    expect($ledger->openReservations())->toBe(0)->and($ledger->balance)->toBe('100.0000')
        ->and($store->lastAssistant()['status'])->toBe('partial');
});

it('regenerates: answers the last user message again and hides the old answer', function () {
    [$svc, , $store] = chat(new FakeEngine([ev('first', 5, 5)]));
    $first = run($svc, req('question'));
    $convId = $first[0]['conversation_id'];
    $oldId = $first[0]['message_id'];

    $svc2 = chat(new FakeEngine([ev('second', 5, 5)]), null, $store)[0];
    $again = run($svc2, req(null, $convId));

    expect(end($again)['type'])->toBe('done')
        ->and($store->messages[$oldId]['hidden'])->toBeTrue()
        ->and(count($store->history(T, $convId)))->toBe(2)
        ->and($store->history(T, $convId)[1]['content'])->toBe('second');
});

it('keeps a failed regenerate from destroying the old answer', function () {
    [$svc, , $store] = chat(new FakeEngine([ev('keep me', 5, 5)]));
    $first = run($svc, req('q'));
    $svc2 = chat(new FakeEngine([new ProviderCallException('timeout', 'x')]), null, $store)[0];
    run($svc2, req(null, $first[0]['conversation_id']));

    expect($store->messages[$first[0]['message_id']]['hidden'])->toBeFalse();
});

it('will not open someone else\'s conversation', function () {
    [$svc, , $store] = chat(new FakeEngine([ev('x')]));
    $convId = $store->createConversation(T, 'OTHERUSER0000000000000000B', 't', null);
    $events = run($svc, req('hi', $convId));

    expect($events[0]['code'])->toBe('not_found');
});

it('reports when no model is available', function () {
    [$svc] = chat(new FakeEngine, null, null, new FakePicker(null));
    expect(run($svc, req())[0]['code'])->toBe('no_model');
});

it('rejects empty messages and regenerate without a conversation', function () {
    [$svc] = chat(new FakeEngine);
    expect(run($svc, req('   '))[0]['code'])->toBe('empty_message')
        ->and(run($svc, req(null))[0]['code'])->toBe('nothing_to_regenerate');
});
