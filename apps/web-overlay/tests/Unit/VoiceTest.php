<?php

use App\Chat\CreditPricing;
use App\Voice\AudioInput;
use App\Voice\VoicePricing;
use App\Voice\VoiceService;
use App\Voice\VoiceText;
use Tests\Support\FakeAssistEngine;
use Tests\Support\FakeLedger;
use Tests\Support\FakePicker;
use Tests\Support\FakeVoiceEngine;
use Tests\Support\FakeVoicePicker;
use Tests\Support\ProviderDown;

const VT = 'TENANT0000000000000000000A';
const VUS = 'USER000000000000000000000A';

function vwav(int $n = 1000): string
{
    return 'RIFF'."\x00\x00\x00\x00".'WAVE'.str_repeat("\x00", max(0, $n - 12));
}

function vsvc(?FakeVoiceEngine $e = null, ?FakeLedger $l = null, ?FakeVoicePicker $vp = null, ?FakeAssistEngine $chat = null, ?FakePicker $mp = null): array
{
    $e ??= new FakeVoiceEngine;
    $l ??= new FakeLedger('100.0000');
    $chat ??= new FakeAssistEngine(['text' => ' সাড়ে ছয়শো টাকা। ', 'input_tokens' => 200, 'output_tokens' => 20, 'model' => 'm']);

    return [new VoiceService($e, $vp ?? new FakeVoicePicker, $mp ?? new FakePicker, $chat, $l, new VoicePricing('1', '1'), new CreditPricing('1', '3')), $e, $l, $chat];
}

// ---------- recording check
it('recognises recordings by their first bytes, not by what the browser says', function () {
    $pad = str_repeat("\x00", 300);
    expect(AudioInput::validate("\x1A\x45\xDF\xA3".$pad)['mime'])->toBe('audio/webm');
    expect(AudioInput::validate('OggS'.$pad)['mime'])->toBe('audio/ogg');
    expect(AudioInput::validate('ID3'.$pad)['mime'])->toBe('audio/mpeg');
    expect(AudioInput::validate("\xFF\xFB".$pad)['mime'])->toBe('audio/mpeg');
    expect(AudioInput::validate(vwav(300))['mime'])->toBe('audio/wav');
    expect(AudioInput::validate("\x00\x00\x00\x20ftypM4A ".$pad)['mime'])->toBe('audio/mp4');
    expect(AudioInput::validate('RIFF'."\x00\x00\x00\x00".'AVI '.$pad)['error'])->toBe('bad_type');
    expect(AudioInput::validate("\x89PNG".$pad)['error'])->toBe('bad_type');
    expect(AudioInput::validate("\xFF\xD8\xFF".$pad)['error'])->toBe('bad_type');
});

it('limits the size of a recording', function () {
    expect(AudioInput::validate('')['error'])->toBe('empty');
    expect(AudioInput::validate(str_repeat('a', 199))['error'])->toBe('too_small');
    expect(AudioInput::validate('OggS'.str_repeat('a', 196))['error'])->toBeNull();
    expect(AudioInput::validate('OggS'.str_repeat('a', 4_999_996))['error'])->toBeNull();
    expect(AudioInput::validate('OggS'.str_repeat('a', 4_999_997))['error'])->toBe('too_big');
});

// ---------- price
it('prices listening by megabyte and speaking by 1000 characters, rounded up', function () {
    $p = new VoicePricing('1', '1');
    expect($p->listen(1_000_000))->toBe('1.0000');
    expect($p->listen(500_000))->toBe('0.5000');
    expect($p->listen(1))->toBe('0.0001');
    expect($p->listen(0))->toBe('0.0001');
    expect($p->speak(1000))->toBe('1.0000');
    expect($p->speak(1500))->toBe('1.5000');
    expect($p->speak(1))->toBe('0.0010');
    expect($p->listen(1_000_000, 2.5))->toBe('2.5000');
    expect((new VoicePricing('0.5', '3'))->speak(2000))->toBe('6.0000');
    expect((new VoicePricing('0.5', '3'))->listen(2_000_000))->toBe('1.0000');
    expect(strlen(VoiceText::message('no_model')) > 10)->toBeTrue();
    expect(VoiceText::message('zzz'))->toBe('Could not be done.');
});

// ---------- listen
it('turns a recording into text and charges the size-based price once', function () {
    [$s, $e, $l] = vsvc();
    $r = $s->listen(VT, VUS, vwav(500_000), 'bn', 'r1');
    expect($r)->toBe(['status' => 'ok', 'text' => 'আজকের দাম কত?', 'credits' => '0.5000']);
    expect($e->heard[0]['mime'])->toBe('audio/wav');
    expect($e->heard[0]['language'])->toBe('bn');
    expect($e->heard[0]['size'])->toBe(500_000);
    expect($l->settleKeys)->toBe(['voice-settle:listen:r1']);
    expect($l->usages[0]['source'])->toBe('voice');
    expect($l->releaseKeys)->toBe([]);
    expect($l->openReservations())->toBe(0);
    expect(round((float) $l->balance, 4))->toBe(99.5);
});

it('refuses bad recordings and languages before anything is reserved', function () {
    [$s, $e, $l] = vsvc();
    expect($s->listen(VT, VUS, '', null, 'r')['status'])->toBe('empty');
    expect($s->listen(VT, VUS, 'tiny', null, 'r')['status'])->toBe('too_small');
    expect($s->listen(VT, VUS, "\x89PNG".str_repeat('a', 400), null, 'r')['status'])->toBe('bad_type');
    expect($s->listen(VT, VUS, vwav(), 'xx', 'r')['status'])->toBe('bad_language');
    expect($s->listen(VT, VUS, vwav(), '', 'r')['status'])->toBe('ok');
    expect($e->heard[0]['language'])->toBeNull();
    expect(count($l->reserveKeys))->toBe(1);
});

it('says no_model and no_credits without calling the provider', function () {
    [$s, $e] = vsvc(null, null, new FakeVoicePicker(null, null));
    expect($s->listen(VT, VUS, vwav(), null, 'r')['status'])->toBe('no_model');
    expect($s->speak(VT, VUS, 'হ্যালো', 'alloy', 'r')['status'])->toBe('no_model');
    [$s, $e] = vsvc(null, new FakeLedger('0.0000'));
    expect($s->listen(VT, VUS, vwav(), null, 'r')['status'])->toBe('no_credits');
    expect($s->speak(VT, VUS, 'হ্যালো', 'alloy', 'r')['status'])->toBe('no_credits');
    expect($e->heard)->toBe([]);
    expect($e->spoken)->toBe([]);
});

it('releases the hold when the provider fails or cannot do speech, and keeps the charge when nothing was said', function () {
    [$s, , $l] = vsvc(new FakeVoiceEngine(ProviderDown::make()));
    expect($s->listen(VT, VUS, vwav(), null, 'r')['status'])->toBe('provider');
    expect($l->releaseKeys)->toBe(['voice-release:r-listen']);
    expect($l->settleKeys)->toBe([]);
    expect($l->openReservations())->toBe(0);
    [$s, , $l] = vsvc(new FakeVoiceEngine(new RuntimeException('x')));
    expect($s->listen(VT, VUS, vwav(), null, 'r')['status'])->toBe('failed');
    expect($l->openReservations())->toBe(0);
    [$s, , $l] = vsvc(new FakeVoiceEngine(['status' => 'unsupported', 'reason' => 'adapter_no_speech']));
    expect($s->listen(VT, VUS, vwav(), null, 'r')['status'])->toBe('unsupported');
    expect($l->settleKeys)->toBe([]);
    [$s, , $l] = vsvc(new FakeVoiceEngine(['status' => 'empty', 'text' => '']));
    $r = $s->listen(VT, VUS, vwav(), null, 'r');
    expect($r['status'])->toBe('no_speech');
    expect($r['text'])->toBeNull();
    expect(count($l->settleKeys))->toBe(1);   // the provider did the work, so it is paid
    [$s, , $l] = vsvc();
    $l->failSettle = true;
    expect($s->listen(VT, VUS, vwav(), null, 'r')['status'])->toBe('ok');
});

// ---------- speak
it('turns text into audio and charges per 1000 characters', function () {
    [$s, $e, $l] = vsvc();
    $r = $s->speak(VT, VUS, str_repeat('ক', 1500)."\x00", 'nova', 'r2');
    expect($r['status'])->toBe('ok');
    expect($r['mime'])->toBe('audio/mpeg');
    expect($r['audio_b64'])->toBe('QUJD');
    expect($r['credits'])->toBe('1.5000');
    expect(mb_strlen($e->spoken[0]['text']))->toBe(1500);
    expect($e->spoken[0]['voice'])->toBe('nova');
    expect($l->settleKeys)->toBe(['voice-settle:speak:r2']);
});

it('refuses bad speak input', function () {
    [$s, $e, $l] = vsvc();
    expect($s->speak(VT, VUS, ' ', 'alloy', 'r')['status'])->toBe('empty_text');
    expect($s->speak(VT, VUS, str_repeat('ক', 2001), 'alloy', 'r')['status'])->toBe('too_long');
    expect($s->speak(VT, VUS, str_repeat('ক', 2000), 'alloy', 'r')['status'])->toBe('ok');
    expect($s->speak(VT, VUS, 'হ্যালো', '../x', 'r')['status'])->toBe('bad_voice');
    expect(count($e->spoken))->toBe(1);
});

it('fails (but charges) when the provider says ok with no audio, and releases on provider error', function () {
    [$s, , $l] = vsvc(null, null, null);
    $e = new FakeVoiceEngine(null, ['status' => 'ok', 'audio_b64' => '']);
    [$s, , $l] = vsvc($e);
    expect($s->speak(VT, VUS, 'হ্যালো', 'alloy', 'r')['status'])->toBe('failed');
    expect(count($l->settleKeys))->toBe(1);
    [$s, , $l] = vsvc(new FakeVoiceEngine(null, ProviderDown::make()));
    expect($s->speak(VT, VUS, 'হ্যালো', 'alloy', 'r')['status'])->toBe('provider');
    expect($l->releaseKeys)->toBe(['voice-release:r-speak']);
});

// ---------- ask
it('listens, answers briefly, speaks, and adds up the cost of all three steps', function () {
    [$s, $e, $l, $chat] = vsvc();
    $r = $s->ask(VT, VUS, vwav(500_000), '', 'bn', true, 'alloy', 'q1');
    expect($r['status'])->toBe('ok');
    expect($r['transcript'])->toBe('আজকের দাম কত?');
    expect($r['answer'])->toBe('সাড়ে ছয়শো টাকা।');
    expect($r['audio_b64'])->toBe('QUJD');
    expect($r['speak_status'])->toBe('ok');
    expect($e->spoken[0]['text'])->toBe('সাড়ে ছয়শো টাকা।');
    expect($chat->seen[0]['messages'][0]['content'])->toBe(VoiceService::ANSWER_SYSTEM);
    expect($chat->seen[0]['max'])->toBe(400);
    expect(str_contains($chat->seen[0]['messages'][1]['content'], 'আজকের দাম কত?'))->toBeTrue();
    // 0.5 (listen) + chat (200*1 + 20*3)/1000 = 0.26 + speak 17 chars -> 0.0170
    expect($r['credits'])->toBe('0.7770');
    expect(count($l->settleKeys))->toBe(3);
    expect($l->openReservations())->toBe(0);
    expect(count(array_unique($l->reserveKeys)))->toBe(3);
});

it('answers a typed question without listening and without speaking when not asked', function () {
    [$s, $e] = vsvc();
    $r = $s->ask(VT, VUS, null, "  দাম কত?\x00 ", null, false, 'alloy', 'q2');
    expect($r['status'])->toBe('ok');
    expect($r['transcript'])->toBe('দাম কত?');
    expect($e->heard)->toBe([]);
    expect($e->spoken)->toBe([]);
    expect($r['audio_b64'])->toBeNull();
    expect($r['speak_status'])->toBeNull();
    expect($s->ask(VT, VUS, null, '  ', null, false, 'alloy', 'q3')['status'])->toBe('empty');
    expect($s->ask(VT, VUS, '', '', null, false, 'alloy', 'q3')['status'])->toBe('empty');
    expect($s->ask(VT, VUS, null, str_repeat('ক', 2001), null, false, 'alloy', 'q3')['status'])->toBe('too_long');
});

it('stops at the first failing step but keeps the cost of the steps that worked', function () {
    [$s, , $l] = vsvc(new FakeVoiceEngine(['status' => 'empty', 'text' => '']));
    $r = $s->ask(VT, VUS, vwav(1_000_000), '', null, true, 'alloy', 'q4');
    expect($r['status'])->toBe('no_speech');
    expect($r['credits'])->toBe('1.0000');
    expect($r['answer'])->toBeNull();
    // answer fails (provider down): listen stays charged, answer hold released
    [$s, , $l] = vsvc(null, null, null, new FakeAssistEngine(ProviderDown::make()));
    $r = $s->ask(VT, VUS, vwav(1_000_000), '', null, true, 'alloy', 'q5');
    expect($r['status'])->toBe('provider');
    expect($r['transcript'])->toBe('আজকের দাম কত?');
    expect($r['credits'])->toBe('1.0000');
    expect($l->openReservations())->toBe(0);
    // speaking fails: the answer is still returned in writing
    [$s, , $l] = vsvc(new FakeVoiceEngine(null, ProviderDown::make()));
    $r = $s->ask(VT, VUS, null, 'Question', null, true, 'alloy', 'q6');
    expect($r['status'])->toBe('ok');
    expect($r['answer'])->toBe('সাড়ে ছয়শো টাকা।');
    expect($r['speak_status'])->toBe('provider');
    expect($r['audio_b64'])->toBeNull();
});

it('reports no chat model and an unreadable answer', function () {
    [$s] = vsvc(null, null, null, null, new FakePicker(null));
    expect($s->ask(VT, VUS, null, 'Question', null, false, 'alloy', 'q7')['status'])->toBe('no_model');
    [$s, , $l] = vsvc(null, null, null, new FakeAssistEngine(['text' => ' ', 'input_tokens' => 100, 'output_tokens' => 0, 'model' => 'm']));
    $r = $s->ask(VT, VUS, null, 'Question', null, false, 'alloy', 'q8');
    expect($r['status'])->toBe('failed');
    expect(count($l->settleKeys))->toBe(1);
    expect($r['credits'] !== '0.0000')->toBeTrue();
});

// ---------- extra edges
it('uses typed text when the audio field is an empty string and names each charge distinctly', function () {
    [$s, $e, $l] = vsvc();
    $r = $s->ask(VT, VUS, '', 'লিখে প্রশ্ন', null, true, 'alloy', 'q1');
    expect($r['status'])->toBe('ok');
    expect($e->heard)->toBe([]);
    expect($l->reserveKeys)->toBe(['voice:q1-a', 'voice-speak:q1-s']);
    expect($l->settleKeys)->toBe(['voice-settle:q1-a', 'voice-settle:speak:q1-s']);
    [$s, $e, $l] = vsvc();
    $s->ask(VT, VUS, vwav(), '', null, false, 'alloy', 'q2');
    expect($l->reserveKeys)->toBe(['voice-listen:q2-l', 'voice:q2-a']);
});

it('clamps odd token counts and long answers', function () {
    [$s, , $l] = vsvc(null, null, null, new FakeAssistEngine(['text' => str_repeat('ক', 2500), 'input_tokens' => -50, 'output_tokens' => 0, 'model' => 'm']));
    $r = $s->ask(VT, VUS, null, 'Question', null, false, 'alloy', 'q');
    expect(mb_strlen($r['answer']))->toBe(2000);
    expect($r['credits'])->toBe('0.0000');
});

it('rounds up the price and ignores negative sizes; AAC frames are not mp3', function () {
    $p = new VoicePricing('1', '1');
    expect($p->listen(1_000_001))->toBe('1.0001');
    expect($p->listen(-5))->toBe('0.0001');
    expect(AudioInput::validate("\xFF\xF1".str_repeat("\x00", 300))['error'])->toBe('bad_type');
});

// ---------- spoken commands: "<workflow name> চালাও"
use App\Voice\VoiceCommand;
use App\Voice\WorkflowVoiceActions;
use Tests\Support\FakeVoiceActions;

function vwf(string $id, string $name, bool $enabled = true, bool $needs = false): array
{
    return ['id' => $id, 'name' => $name, 'enabled' => $enabled, 'needs_input' => $needs];
}

it('reads the name out of a start command in Bengali or English and ignores everything else', function () {
    foreach ([
        'সাপ্তাহিক মার্কেটিং ম্যানেজার চালাও' => 'Weekly marketing manager',
        'হে জার্ভিস, সাপ্তাহিক রিপোর্ট চালাও!' => 'সাপ্তাহিক রিপোর্ট',
        'সাপ্তাহিক রিপোর্ট ওয়ার্কফ্লোটি চালিয়ে দাও।' => 'সাপ্তাহিক রিপোর্ট',
        'আমার সাপ্তাহিক রিপোর্ট এজেন্ট রান করো' => 'সাপ্তাহিক রিপোর্ট',
        'Run weekly report' => 'weekly report',
        'Hey Jarvis, please START the weekly report workflow.' => 'weekly report',
        'চালাও সাপ্তাহিক রিপোর্ট' => 'সাপ্তাহিক রিপোর্ট',
        'রিপোর্ট শুরু করুন' => 'রিপোর্ট',
    ] as $said => $name) {
        expect([$said, VoiceCommand::target($said)])->toBe([$said, $name]);
    }
    foreach (['আজকের দাম কত?', '', '   ', 'চালাও', 'run', 'হে জার্ভিস চালাও', 'বলো কিভাবে ওয়ার্কফ্লো চালাতে হয়', 'what does run mean', 'আমাকে বলো যে সাপ্তাহিক রিপোর্ট কখন চালাও করতে হয় আর কেন', 'run the the workflow', 'এজেন্ট চালাও'] as $no) {
        expect([$no, VoiceCommand::target($no)])->toBe([$no, null]);
    }
    expect(VoiceCommand::target('run one two three four five six seven'))->toBe('one two three four five six seven');     // 8 words: the limit
    expect(VoiceCommand::target('run one two three four five six seven eight'))->toBeNull();                                   // 9 words
});

it('finds exactly one of the person\'s workflows: exact name first, then a unique part of a name', function () {
    $w = [vwf('1', 'সাপ্তাহিক রিপোর্ট'), vwf('2', 'মাসিক রিপোর্ট'), vwf('3', 'আজকের টাস্ক'), vwf('4', 'রিপোর্ট')];
    expect(VoiceCommand::find('রিপোর্ট', $w)['workflow']['id'])->toBe('4');                    // exact wins over parts
    expect(VoiceCommand::find('সাপ্তাহিক', $w)['workflow']['id'])->toBe('1');
    expect(VoiceCommand::find('  সাপ্তাহিক   রিপোর্ট! ', $w)['workflow']['id'])->toBe('1');
    expect(VoiceCommand::find('আজকের টাস্ক এখনই', $w)['workflow']['id'])->toBe('3');           // the name is inside what was said
    $a = VoiceCommand::find('রিপোর্ট', [vwf('1', 'সাপ্তাহিক রিপোর্ট'), vwf('2', 'মাসিক রিপোর্ট')]);
    expect($a['status'])->toBe('ambiguous');
    expect($a['names'])->toBe(['সাপ্তাহিক রিপোর্ট', 'মাসিক রিপোর্ট']);
    expect(VoiceCommand::find('অন্য কিছু', $w)['status'])->toBe('none');
    expect(VoiceCommand::find('রি', $w)['status'])->toBe('too_short');                          // under 3 characters never guesses
    expect(VoiceCommand::find('RUN Weekly', [vwf('9', 'run weekly')])['workflow']['id'])->toBe('9');
    expect(VoiceCommand::find('x', [])['status'])->toBe('too_short');
    expect(VoiceCommand::find('সাপ্তাহিক রিপোর্ট', [vwf('1', 'রি')])['status'])->toBe('none');       // a 2-letter workflow name is never matched inside a longer sentence
});

it('starts the workflow once and says which one it started', function () {
    $a = new FakeVoiceActions;
    $a->list = [vwf('w1', 'সাপ্তাহিক রিপোর্ট'), vwf('w2', 'আজকের টাস্ক')];
    $r = VoiceCommand::run('সাপ্তাহিক রিপোর্ট চালাও', VT, VUS, $a);
    expect($r['command'])->toBe('started');
    expect($r['text'])->toContain('সাপ্তাহিক রিপোর্ট')->toContain('Approval');
    expect($a->started)->toBe([[VT, VUS, 'w1']]);
    expect($a->listedFor)->toBe(VUS);
    expect(VoiceCommand::run('আজকের দাম কত?', VT, VUS, $a))->toBeNull();                       // not a command: the normal answer path
    expect(count($a->started))->toBe(1);
});

it('never starts a workflow it is not sure about, one that needs typed input, or a disabled one', function () {
    $a = new FakeVoiceActions;
    $a->list = [vwf('1', 'সাপ্তাহিক রিপোর্ট'), vwf('2', 'মাসিক রিপোর্ট'), vwf('3', 'Write email', true, true), vwf('4', 'পুরনো কাজ', false)];
    expect(VoiceCommand::run('রিপোর্ট চালাও', VT, VUS, $a)['command'])->toBe('ambiguous');
    expect(VoiceCommand::run('কিছু একটা চালাও', VT, VUS, $a)['command'])->toBe('not_found');
    expect(VoiceCommand::run('ইমেইল লেখা চালাও', VT, VUS, $a)['command'])->toBe('needs_input');
    expect(VoiceCommand::run('পুরনো কাজ চালাও', VT, VUS, $a)['command'])->toBe('disabled');
    expect(VoiceCommand::run('রি চালাও', VT, VUS, $a)['command'])->toBe('not_found');
    expect($a->started)->toBe([]);
});

it('tells the person when the run could not begin', function () {
    $a = new FakeVoiceActions;
    $a->list = [vwf('1', 'সাপ্তাহিক রিপোর্ট')];
    foreach (['busy' => 'busy', 'disabled' => 'disabled', 'missing_input' => 'needs_input', 'not_found' => 'not_found', 'failed' => 'failed', 'weird' => 'failed'] as $res => $cmd) {
        $a->result = $res;
        $r = VoiceCommand::run('সাপ্তাহিক রিপোর্ট চালাও', VT, VUS, $a);
        expect([$res, $r['command']])->toBe([$res, $cmd]);
        expect($r['text'] !== '')->toBeTrue();
    }
});

it('a spoken command is answered without any AI call or AI cost, and can be read aloud', function () {
    $a = new FakeVoiceActions;
    $a->list = [vwf('w1', 'সাপ্তাহিক রিপোর্ট')];
    $chat = new FakeAssistEngine(['text' => 'x', 'input_tokens' => 1, 'output_tokens' => 1, 'model' => 'm']);
    $e = new FakeVoiceEngine;
    $l = new FakeLedger('100.0000');
    $s = new VoiceService($e, new FakeVoicePicker, new FakePicker, $chat, $l, new VoicePricing('1', '1'), new CreditPricing('1', '3'), $a);
    $r = $s->ask(VT, VUS, null, 'সাপ্তাহিক রিপোর্ট চালাও', null, false, 'alloy', 'c1');
    expect($r['status'])->toBe('ok');
    expect($r['command'])->toBe('started');
    expect($r['transcript'])->toBe('সাপ্তাহিক রিপোর্ট চালাও');
    expect($r['credits'])->toBe('0.0000');
    expect($chat->seen)->toBe([]);
    expect($l->reserveKeys)->toBe([]);
    expect($a->started)->toBe([[VT, VUS, 'w1']]);
    $r2 = $s->ask(VT, VUS, null, 'সাপ্তাহিক রিপোর্ট চালাও', null, true, 'alloy', 'c2');       // read aloud: only the voice is charged
    expect($e->spoken[0]['text'])->toBe($r2['answer']);
    expect($r2['speak_status'])->toBe('ok');
    expect(count($l->reserveKeys))->toBe(1);
    $r3 = $s->ask(VT, VUS, null, 'আজকের দাম কত?', null, false, 'alloy', 'c3');                  // an ordinary question still goes to the AI
    expect($r3['command'])->toBeNull();
    expect(count($chat->seen))->toBe(1);
    [$plain, , , $chat2] = vsvc();                                                                // without the actions the sentence is just a question
    expect($plain->ask(VT, VUS, null, 'সাপ্তাহিক রিপোর্ট চালাও', null, false, 'alloy', 'c4')['command'])->toBeNull();
    expect(count($chat2->seen))->toBe(1);
});

it('the workflow adapter lists the person\'s workflows, marks the ones that need input, and maps start results', function () {
    $repo = new Tests\Support\FakeWorkflowRepo;
    $svc = new App\Workflow\WorkflowService($repo, new App\Workflow\ToolRegistry([]));
    $ok = ['start' => 'o', 'nodes' => ['o' => ['type' => 'output', 'text' => 'hi']]];
    $in = ['start' => 'o', 'inputs' => [['key' => 'x', 'label' => 'X']], 'nodes' => ['o' => ['type' => 'output', 'text' => 'hi {{x}}']]];
    $repo->save(VT, VUS, 'w1', 'General', '', 'personal', $ok, true, null, 1.0, true);
    $repo->save(VT, VUS, 'w2', 'ইনপুটসহ', '', 'personal', $in, true, null, 1.0, true);
    $repo->save(VT, 'other', 'w3', 'অন্যের', '', 'personal', $ok, true, null, 1.0, true);
    $act = new WorkflowVoiceActions($repo, $svc);
    expect($act->workflows(VUS))->toBe([['id' => 'w1', 'name' => 'General', 'enabled' => true, 'needs_input' => false], ['id' => 'w2', 'name' => 'ইনপুটসহ', 'enabled' => true, 'needs_input' => true]]);
    expect($act->startWorkflow(VT, VUS, 'w1'))->toBe('ok');
    expect($act->startWorkflow(VT, VUS, 'w2'))->toBe('missing_input');
    expect($act->startWorkflow(VT, VUS, 'w3'))->toBe('not_found');                              // someone else's workflow is invisible
    expect($act->startWorkflow(VT, VUS, 'nope'))->toBe('not_found');
});
