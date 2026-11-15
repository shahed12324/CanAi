<?php

use App\Chat\ChatRequest;
use App\Chat\ChatService;
use App\Chat\ContextBuilder;
use App\Chat\CreditPricing;
use Tests\Support\FakeEngine;
use Tests\Support\FakeLedger;
use Tests\Support\FakeMemoryContext;
use Tests\Support\FakePicker;
use Tests\Support\FakeStore;

function mchat(?FakeMemoryContext $m): array
{
    $engine = new FakeEngine([['text' => 'OK', 'input_tokens' => 10, 'output_tokens' => 5]]);
    $svc = new ChatService(new FakePicker, new FakeStore, $engine, new FakeLedger('100.0000'), new CreditPricing('1', '3'), 2048, 24000, $m);

    return [$svc, $engine];
}

function mrun(ChatService $svc): array
{
    return iterator_to_array($svc->send(new ChatRequest('TENANT0000000000000000000A', 'USER000000000000000000000A', null, 'hello')), false);
}

it('adds the saved notes to the system prompt and says how many it used', function () {
    [$svc, $engine] = mchat(new FakeMemoryContext);
    $events = mrun($svc);
    expect($events[0]['memory'])->toBe(2);
    $system = $engine->seenMessages[0][0];
    expect($system['role'])->toBe('system');
    expect($system['content'])->toBe(ContextBuilder::SYSTEM_PROMPT."\n\nNOTES-BLOCK");
});

it('sends the plain prompt when there are no notes or memory is not wired in', function () {
    [$svc, $engine] = mchat(new FakeMemoryContext(['text' => null, 'count' => 0]));
    expect(mrun($svc)[0]['memory'])->toBe(0);
    expect($engine->seenMessages[0][0]['content'])->toBe(ContextBuilder::SYSTEM_PROMPT);
    [$svc2, $engine2] = mchat(null);
    expect(mrun($svc2)[0]['memory'])->toBe(0);
    expect($engine2->seenMessages[0][0]['content'])->toBe(ContextBuilder::SYSTEM_PROMPT);
});

it('never lets a memory failure stop the chat', function () {
    [$svc, $engine] = mchat(new FakeMemoryContext(new RuntimeException('db down')));
    $events = mrun($svc);
    expect(array_column($events, 'type'))->toBe(['meta', 'delta', 'done']);
    expect($events[0]['memory'])->toBe(0);
    expect($engine->seenMessages[0][0]['content'])->toBe(ContextBuilder::SYSTEM_PROMPT);
});
