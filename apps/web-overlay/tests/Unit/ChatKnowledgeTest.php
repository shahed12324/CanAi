<?php

use App\Chat\ChatRequest;
use App\Chat\ChatService;
use App\Chat\ContextBuilder;
use App\Chat\CreditPricing;
use Tests\Support\FakeEngine;
use Tests\Support\FakeKnowledgeContext;
use Tests\Support\FakeLedger;
use Tests\Support\FakePicker;
use Tests\Support\FakeStore;

function kchat(?FakeKnowledgeContext $k, ?FakeEngine $engine = null, ?FakeLedger $ledger = null, ?FakeStore $store = null): array
{
    $engine ??= new FakeEngine([['text' => 'কাজুর দাম ১২০০ টাকা [1]।', 'input_tokens' => 10, 'output_tokens' => 5]]);
    $store ??= new FakeStore;
    $svc = new ChatService(new FakePicker, $store, $engine, $ledger ?? new FakeLedger('100.0000'), new CreditPricing('1', '3'), 2048, 24000, null, $k);

    return [$svc, $engine, $store];
}

function krun(ChatService $svc, bool $kb = true, ?string $text = 'কাজুর দাম কত?', ?string $conv = null): array
{
    return iterator_to_array($svc->send(new ChatRequest('TENANT0000000000000000000A', 'USER000000000000000000000A', $conv, $text, [], null, $kb)), false);
}

it('puts the documents in the system prompt and reports the sources, marking the ones quoted', function () {
    $k = new FakeKnowledgeContext;
    [$svc, $engine] = kchat($k);
    $events = krun($svc);
    expect($events[0]['knowledge'])->toBe(2);
    expect($engine->seenMessages[0][0]['content'])->toBe(ContextBuilder::SYSTEM_PROMPT."\n\nDOCS-BLOCK");
    expect($k->questions)->toBe(['কাজুর দাম কত?']);
    $done = end($events);
    expect($done['type'])->toBe('done');
    expect(array_column($done['sources'], 'cited'))->toBe([true, false]);
    expect($k->recorded)->toHaveCount(1);
    expect($k->recorded[0][0])->toBe($done['message_id']);
    expect($k->recorded[0][1])->toBe($done['sources']);
});

it('does not touch the documents when the person switched them off', function () {
    $k = new FakeKnowledgeContext;
    [$svc, $engine] = kchat($k);
    $events = krun($svc, false);
    expect($k->questions)->toBe([]);
    expect($events[0]['knowledge'])->toBe(0);
    expect(end($events)['sources'])->toBe([]);
    expect($k->recorded)->toBe([]);
    expect($engine->seenMessages[0][0]['content'])->toBe(ContextBuilder::SYSTEM_PROMPT);
});

it('answers as before when nothing matched or knowledge is not wired in', function () {
    foreach ([new FakeKnowledgeContext(['text' => null, 'sources' => []]), null] as $k) {
        [$svc, $engine] = kchat($k);
        $events = krun($svc);
        expect($events[0]['knowledge'])->toBe(0);
        expect(end($events)['sources'])->toBe([]);
        expect($engine->seenMessages[0][0]['content'])->toBe(ContextBuilder::SYSTEM_PROMPT);
        if ($k !== null) {
            expect($k->recorded)->toBe([]);
        }
    }
});

it('never lets a documents failure stop the chat', function () {
    [$svc, $engine] = kchat(new FakeKnowledgeContext(new RuntimeException('db down')));
    $events = krun($svc);
    expect(array_column($events, 'type'))->toBe(['meta', 'delta', 'done']);
    expect($events[0]['knowledge'])->toBe(0);
    expect($engine->seenMessages[0][0]['content'])->toBe(ContextBuilder::SYSTEM_PROMPT);
});

it('still delivers the answer when saving the sources fails', function () {
    $k = new FakeKnowledgeContext;
    $k->recordFails = new RuntimeException('db down');
    [$svc] = kchat($k);
    $events = krun($svc);
    $done = end($events);
    expect($done['type'])->toBe('done');
    expect(count($done['sources']))->toBe(2);
});

it('records no sources when the answer fails or credits are missing', function () {
    $k = new FakeKnowledgeContext;
    [$svc] = kchat($k, new FakeEngine([new RuntimeException('boom')]));
    $events = krun($svc);
    expect(end($events)['type'])->toBe('error');
    expect($k->recorded)->toBe([]);
    $k2 = new FakeKnowledgeContext;
    [$svc2] = kchat($k2, null, new FakeLedger('0.0000'));
    $ev2 = krun($svc2);
    expect(end($ev2)['type'])->toBe('error');
    expect($k2->recorded)->toBe([]);
});

it('searches with the last question when regenerating', function () {
    $k = new FakeKnowledgeContext;
    [$svc, , $store] = kchat($k);
    $first = krun($svc);
    $conv = $first[0]['conversation_id'];
    $k->questions = [];
    $again = iterator_to_array($svc->send(new ChatRequest('TENANT0000000000000000000A', 'USER000000000000000000000A', $conv, null)), false);
    expect($k->questions)->toBe(['কাজুর দাম কত?']);
    expect($again[0]['knowledge'])->toBe(2);
});

it('lets the request default to using the documents', function () {
    expect((new ChatRequest('T', 'U', null, 'x'))->useKnowledge)->toBeTrue();
});
