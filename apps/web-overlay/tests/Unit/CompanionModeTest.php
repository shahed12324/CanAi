<?php

use App\Chat\ChatRequest;
use App\Chat\ChatService;
use App\Chat\CreditPricing;
use App\Companion\ModeRepo;
use App\Companion\ModeRules;
use Tests\Support\FakeCharacterRepo;
use Tests\Support\FakeEngine;
use Tests\Support\FakeLedger;
use Tests\Support\FakePicker;
use Tests\Support\FakeStore;

const CMT = 'TENANT0000000000000000000A';
const CMU = 'USER000000000000000000000A';

final class FakeModes implements ModeRepo
{
    public function __construct(public array $m = ['mode' => 'agent', 'character_id' => null], public bool $boom = false) {}

    public function get(string $t, string $u): array
    {
        if ($this->boom) {
            throw new RuntimeException('db down');
        }

        return $this->m;
    }

    public function set(string $t, string $u, string $m, ?string $c): string
    {
        $this->m = ['mode' => $m, 'character_id' => $c];

        return 'ok';
    }
}

function cmRun(?ModeRepo $modes, ?FakeCharacterRepo $chars = null, ?string $character = null): FakeEngine
{
    $e = new FakeEngine([['text' => 'ok', 'input_tokens' => 5, 'output_tokens' => 5]]);
    $svc = new ChatService(new FakePicker, new FakeStore, $e, new FakeLedger('100.0000'), new CreditPricing('1', '3'), 2048, 24000, null, null, $chars, $modes);
    iterator_to_array($svc->send(new ChatRequest(CMT, CMU, null, 'hi', [], null, true, 'general', $character)), false);

    return $e;
}

it('puts the companion rules in the prompt and never lets it claim computer actions', function () {
    $sys = cmRun(new FakeModes(['mode' => 'companion', 'character_id' => null]))->seenMessages[0][0]['content'];
    expect($sys)->toContain(ModeRules::COMPANION)->and($sys)->not->toContain(ModeRules::AGENT);
    expect(ModeRules::COMPANION)->toContain('cannot open, read, change, move or delete')->and(ModeRules::COMPANION)->toContain('never say or imply that you did');
});

it('puts the agent honesty rules in the prompt in agent mode, and nothing when no repo is wired', function () {
    expect(cmRun(new FakeModes)->seenMessages[0][0]['content'])->toContain(ModeRules::AGENT);
    $plain = cmRun(null)->seenMessages[0][0]['content'];
    expect($plain)->not->toContain('Mode: COMPANION')->and($plain)->not->toContain('Mode: AGENT');
});

it('a failing mode lookup never stops the chat', function () {
    $e = cmRun(new FakeModes(boom: true));
    expect($e->calls)->toBe(1)->and($e->seenMessages[0][0]['content'])->toContain(ModeRules::AGENT);
});

it('starts a new companion chat with the companion\'s own character, but a picked character wins', function () {
    $chars = new FakeCharacterRepo;
    $own = $chars->create(CMT, CMU, ['name' => 'Neela', 'kind' => 'female', 'personality' => 'warm', 'speaking_style' => '', 'language' => 'bn', 'voice' => null, 'avatar_style' => 'none', 'greeting' => '']);
    $picked = $chars->create(CMT, CMU, ['name' => 'Cyan', 'kind' => 'male', 'personality' => 'calm', 'speaking_style' => '', 'language' => 'bn', 'voice' => null, 'avatar_style' => 'none', 'greeting' => '']);
    $m = new FakeModes(['mode' => 'companion', 'character_id' => $own]);
    expect(cmRun($m, $chars)->seenMessages[0][0]['content'])->toContain('Name: Neela');
    expect(cmRun($m, $chars, $picked)->seenMessages[0][0]['content'])->toContain('Name: Cyan');
    $agent = new FakeModes(['mode' => 'agent', 'character_id' => $own]);
    expect(cmRun($agent, $chars)->seenMessages[0][0]['content'])->not->toContain('Name: Neela');       // the default character is a companion-mode thing
    $gone = new FakeModes(['mode' => 'companion', 'character_id' => 'X'.str_repeat('0', 25)]);
    expect(cmRun($gone, $chars)->calls)->toBe(1);                                                         // a deleted companion character falls back to the plain assistant
});

it('validates modes', function () {
    expect(ModeRules::valid('companion'))->toBeTrue()->and(ModeRules::valid('agent'))->toBeTrue()->and(ModeRules::valid('root'))->toBeFalse();
    expect(ModeRules::block('anything-else'))->toBe(ModeRules::AGENT);
});
