<?php

use App\Character\Avatar;
use App\Character\Emotion;
use App\Character\Visemes;

it('draws a well-formed svg for every kind, expression and mouth', function () {
    foreach (['male', 'female', 'custom'] as $k) {
        foreach (Avatar::EXPRESSIONS as $e) {
            foreach (Avatar::MOUTHS as $m) {
                $svg = Avatar::render($k, $e, $m);
                $d = new DOMDocument;
                expect($d->loadXML($svg))->toBeTrue();
                expect($d->documentElement->nodeName)->toBe('svg');
            }
        }
    }
});

it('every expression and every mouth looks different', function () {
    $faces = [];
    foreach (Avatar::EXPRESSIONS as $e) {
        $faces[] = Avatar::render('male', $e, 'closed');
    }
    expect(count(array_unique($faces)))->toBe(5);
    $mouths = [];
    foreach (Avatar::MOUTHS as $m) {
        $mouths[] = Avatar::render('male', 'neutral', $m);
    }
    expect(count(array_unique($mouths)))->toBe(5);
    expect(Avatar::render('male') !== Avatar::render('female'))->toBeTrue();
    expect(Avatar::render('custom') !== Avatar::render('female'))->toBeTrue();
});

it('unknown words fall back to calm defaults and never reach the picture', function () {
    expect(Avatar::render('robot', 'furious', 'huge'))->toBe(Avatar::render('custom', 'neutral', 'closed'));
    $evil = Avatar::render('"><script>alert(1)</script>', '<x>', '"onload=');
    expect(str_contains($evil, 'script') || str_contains($evil, 'onload'))->toBeFalse();
});

it('has no copyrighted names or logos in the drawing', function () {
    expect(preg_match('/<(text|image|use)\b/', Avatar::render('female', 'happy', 'wide')))->toBe(0);
});

it('guesses a feeling from cue words in Bengali and English', function () {
    expect(Emotion::detect('ধন্যবাদ! কাজটি সফল হয়েছে'))->toBe('happy');
    expect(Emotion::detect('Thank you, that is GREAT'))->toBe('happy');
    expect(Emotion::detect('দুঃখিত, এটি করা যায়নি'))->toBe('sad');
    expect(Emotion::detect("I'm sorry about that"))->toBe('sad');
    expect(Emotion::detect('বাহ, দারুণ খবর'))->toBe('surprised');          // surprise wins over happy
    expect(Emotion::detect('সম্ভবত হ্যাঁ'))->toBe('thinking');
    expect(Emotion::detect('Hmm, let me check'))->toBe('thinking');
    expect(Emotion::detect('আপনার অর্ডার নম্বর ১২৩'))->toBe('neutral');
    expect(Emotion::detect(''))->toBe('neutral');
});

it('every feeling it can return can be drawn', function () {
    foreach (['ধন্যবাদ', 'দুঃখিত', 'বাহ', 'ভাবছি', 'OK'] as $t) {
        expect(in_array(Emotion::detect($t), Avatar::EXPRESSIONS, true))->toBeTrue();
    }
});

it('builds a lip-sync track that starts at 0, ends closed at the duration and only uses known mouths', function () {
    $tl = Visemes::timeline('আপনাকে স্বাগতম, কেমন আছেন?', 3000);
    expect($tl[0]['t'])->toBe(0);
    $last = end($tl);
    expect([$last['t'], $last['m']])->toBe([3000, 'closed']);
    $prev = -1;
    foreach ($tl as $c) {
        expect(in_array($c['m'], Avatar::MOUTHS, true))->toBeTrue();
        expect($c['t'] >= $prev)->toBeTrue();
        $prev = $c['t'];
    }
    expect(count(array_unique(array_column($tl, 'm'))) >= 3)->toBeTrue();
});

it('maps letters to mouth shapes', function () {
    $m = fn (string $s) => array_column(Visemes::timeline($s, 1000), 'm')[0];
    expect($m('a'))->toBe('wide');
    expect($m('i'))->toBe('smile');
    expect($m('o'))->toBe('round');
    expect($m('m'))->toBe('closed');
    expect($m('t'))->toBe('open');
    expect($m('আ'))->toBe('wide');
    expect($m('উ'))->toBe('round');
});

it('returns nothing for no speech and caps speed and length', function () {
    expect(Visemes::timeline('', 1000))->toBe([]);
    expect(Visemes::timeline('   ,,, ', 1000))->toBe([['t' => 0, 'm' => 'closed'], ['t' => 1000, 'm' => 'closed']]);
    expect(Visemes::timeline('hello', 0))->toBe([]);
    expect(Visemes::timeline('hello', -5))->toBe([]);
    $long = Visemes::timeline(str_repeat('abeoxm ', 500), 600000);
    expect(count($long) <= Visemes::MAX_STEPS + 1)->toBeTrue();
    $fast = Visemes::timeline(str_repeat('abeo ', 40), 500);
    expect(count($fast) <= 8)->toBeTrue();
    expect(end($fast)['t'])->toBe(500);
    $capped = Visemes::timeline('hi', 999999999);
    expect(end($capped)['t'])->toBe(600000);
});

it('estimates speech length from the text', function () {
    expect(Visemes::estimateMs('hi'))->toBe(600);
    expect(Visemes::estimateMs(str_repeat('ক', 100)))->toBe(6500);
    expect(Visemes::estimateMs(str_repeat('ক', 5000)))->toBe(60000);
});

it('picks the character voice: its own word when known, else a calm default by kind', function () {
    expect(App\Character\CharacterVoice::pick('Nova', 'male'))->toBe('nova');
    expect(App\Character\CharacterVoice::pick(' ECHO ', 'custom'))->toBe('echo');
    expect(App\Character\CharacterVoice::pick(null, 'male'))->toBe('onyx');
    expect(App\Character\CharacterVoice::pick('', 'female'))->toBe('nova');
    expect(App\Character\CharacterVoice::pick('my-own-voice', 'custom'))->toBe('alloy');
    expect(App\Character\CharacterVoice::pick(null, 'weird'))->toBe('alloy');
    foreach (['male', 'female', 'custom'] as $k) {
        expect(isset(App\Voice\VoiceText::VOICES[App\Character\CharacterVoice::pick(null, $k)]))->toBeTrue();
    }
});
