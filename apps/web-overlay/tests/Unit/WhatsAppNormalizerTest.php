<?php

use App\Messaging\InboundMessage;
use App\Messaging\WhatsAppCredentials;
use App\Messaging\WhatsAppNormalizer;

function wa(array $msgs, string $pn = 'PN1'): array
{
    return ['entry' => [['changes' => [['value' => [
        'metadata' => ['phone_number_id' => $pn],
        'contacts' => [['wa_id' => '8801700000000', 'profile' => ['name' => 'Owner']]],
        'messages' => $msgs,
    ]]]]]];
}

it('normalizes text', function () {
    $r = WhatsAppNormalizer::normalize(wa([['id' => 'wamid.1', 'from' => '8801700000000', 'type' => 'text', 'text' => ['body' => 'hi']]]), 'PN1');
    expect($r)->toHaveCount(1);
    expect($r[0]->kind)->toBe(InboundMessage::TEXT);
    expect($r[0]->text)->toBe('hi');
    expect($r[0]->chatId)->toBe('8801700000000');
    expect($r[0]->privateChat)->toBeTrue();
});

it('normalizes voice notes', function () {
    $r = WhatsAppNormalizer::normalize(wa([['id' => 'wamid.2', 'from' => '8801700000000', 'type' => 'audio', 'audio' => ['id' => 'M1', 'mime_type' => 'audio/ogg; codecs=opus']]]), 'PN1');
    expect($r[0]->kind)->toBe(InboundMessage::VOICE);
    expect($r[0]->fileId)->toBe('M1');
});

it('maps button replies to callbacks', function () {
    $r = WhatsAppNormalizer::normalize(wa([['id' => 'wamid.3', 'from' => '8801700000000', 'type' => 'interactive', 'interactive' => ['button_reply' => ['id' => 'ap:5:a']]]]), 'PN1');
    expect($r[0]->kind)->toBe(InboundMessage::CALLBACK);
    expect($r[0]->callbackData)->toBe('ap:5:a');
    expect($r[0]->callbackId)->toBe('8801700000000|wamid.3');
});

it('ignores other numbers and junk', function () {
    expect(WhatsAppNormalizer::normalize(wa([['id' => 'x', 'from' => '8801700000000', 'type' => 'text']], 'OTHER'), 'PN1'))->toBe([]);
    expect(WhatsAppNormalizer::normalize(['entry' => 'bad'], 'PN1'))->toBe([]);
    expect(WhatsAppNormalizer::normalize(wa([['id' => 'x', 'from' => 'abc', 'type' => 'text']]), 'PN1'))->toBe([]);
});

it('has a stable positive numeric id', function () {
    $a = WhatsAppNormalizer::numericId('wamid.abc');
    expect($a)->toBe(WhatsAppNormalizer::numericId('wamid.abc'));
    expect($a)->toBeGreaterThan(0);
});

it('verifies webhook signatures', function () {
    $body = '{"a":1}';
    $sig = 'sha256='.hash_hmac('sha256', $body, 'sec');
    expect(WhatsAppCredentials::signatureValid($body, $sig, 'sec'))->toBeTrue();
    expect(WhatsAppCredentials::signatureValid($body, $sig, 'other'))->toBeFalse();
    expect(WhatsAppCredentials::signatureValid($body, '', 'sec'))->toBeFalse();
});
