<?php

use App\Chat\ChatRequest;
use App\Chat\ChatService;
use App\Chat\ContextBuilder;
use App\Chat\CreditPricing;
use Tests\Support\FakeEngine;
use Tests\Support\FakeLedger;
use Tests\Support\FakePicker;
use Tests\Support\FakeStore;

const CMT = 'TENANT0000000000000000000A';
const CMU = 'USER000000000000000000000A';

function cmSvc(FakeEngine $e, FakeStore $s): ChatService
{
    return new ChatService(new FakePicker, $s, $e, new FakeLedger('100.0000'), new CreditPricing('1', '3'));
}
function cmRun(ChatService $svc, ChatRequest $r): array
{
    return iterator_to_array($svc->send($r), false);
}
function cmEng(): FakeEngine
{
    return new FakeEngine([['text' => 'ok', 'input_tokens' => 5, 'output_tokens' => 5]]);
}

it('uses the coding system prompt for a new code conversation and stores the mode', function () {
    $e = cmEng();
    $s = new FakeStore;
    $ev = cmRun(cmSvc($e, $s), new ChatRequest(CMT, CMU, null, 'fix my bug', [], null, true, 'code'));
    expect($e->seenMessages[0][0]['content'])->toBe(ContextBuilder::CODE_PROMPT);
    expect($ev[0]['mode'])->toBe('code');
    expect(array_values($s->conversations)[0]['mode'])->toBe('code');
});

it('uses the general prompt by default', function () {
    $e = cmEng();
    $s = new FakeStore;
    $ev = cmRun(cmSvc($e, $s), new ChatRequest(CMT, CMU, null, 'hi'));
    expect($e->seenMessages[0][0]['content'])->toBe(ContextBuilder::SYSTEM_PROMPT);
    expect($ev[0]['mode'])->toBe('general');
});

it('an existing conversation keeps its own mode whatever the request says', function () {
    $s = new FakeStore;
    $cid = $s->createConversation(CMT, CMU, 't', null, 'code');
    $e = cmEng();
    $ev = cmRun(cmSvc($e, $s), new ChatRequest(CMT, CMU, $cid, 'more', [], null, true, 'general'));
    expect($e->seenMessages[0][0]['content'])->toBe(ContextBuilder::CODE_PROMPT);
    expect($ev[0]['mode'])->toBe('code');

    $cid2 = $s->createConversation(CMT, CMU, 't2', null, 'general');
    $e2 = cmEng();
    cmRun(cmSvc($e2, $s), new ChatRequest(CMT, CMU, $cid2, 'more', [], null, true, 'code'));
    expect($e2->seenMessages[0][0]['content'])->toBe(ContextBuilder::SYSTEM_PROMPT);
});

it('regenerate keeps the code mode', function () {
    $s = new FakeStore;
    $e = cmEng();
    $ev = cmRun(cmSvc($e, $s), new ChatRequest(CMT, CMU, null, 'q', [], null, true, 'code'));
    $cid = $ev[0]['conversation_id'];
    $e2 = cmEng();
    cmRun(cmSvc($e2, $s), new ChatRequest(CMT, CMU, $cid, null));
    expect($e2->seenMessages[0][0]['content'])->toBe(ContextBuilder::CODE_PROMPT);
});

it('an unknown mode falls back to general', function () {
    $e = cmEng();
    $s = new FakeStore;
    $ev = cmRun(cmSvc($e, $s), new ChatRequest(CMT, CMU, null, 'x', [], null, true, 'root'));
    expect($ev[0]['mode'])->toBe('general');
    expect($e->seenMessages[0][0]['content'])->toBe(ContextBuilder::SYSTEM_PROMPT);
});

it('the coding prompt never promises to run code and asks for fenced, minimal fixes', function () {
    $p = ContextBuilder::CODE_PROMPT;
    expect($p)->toContain('never claim you ran or tested anything');
    expect($p)->toContain('fenced block');
    expect($p)->toContain('smallest change');
    expect($p)->toContain('Bengali');
    expect(ContextBuilder::systemPrompt('code'))->toBe($p);
    expect(ContextBuilder::systemPrompt('anything'))->toBe(ContextBuilder::SYSTEM_PROMPT);
});
