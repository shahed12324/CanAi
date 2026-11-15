<?php

use App\Messaging\InboundMessage;
use App\Messaging\TelegramApi;
use App\Messaging\TelegramNormalizer;
use App\Messaging\TextSplitter;

it('splitter keeps short text whole and never returns empty', function () {
    expect(TextSplitter::split('হ্যালো'))->toBe(['হ্যালো'])->and(TextSplitter::split("  \n "))->toBe(['…']);
});

it('splitter cuts long text on paragraph/word boundaries and loses nothing', function () {
    $text = implode("\n\n", array_map(fn ($i) => "অনুচ্ছেদ $i: ".str_repeat('শব্দ ', 150), range(1, 30)));
    $parts = TextSplitter::split($text, 3800);
    expect(count($parts) > 1)->toBeTrue();
    foreach ($parts as $p) {
        expect(mb_strlen($p) <= 3800 && $p !== '')->toBeTrue();
    }
    expect(preg_replace('/\s+/u', '', implode('', $parts)))->toBe(preg_replace('/\s+/u', '', $text));
});

it('splitter hard-cuts text with no spaces', function () {
    $parts = TextSplitter::split(str_repeat('অ', 9000), 3800);
    expect($parts)->toHaveCount(3)->and(mb_strlen(implode('', $parts)))->toBe(9000);
});

it('bot token is scrubbed from error text', function () {
    $t = '123456789:FAKEFAKEFAKEFAKEFAKEFAKEFAKEFAKE';
    expect(TelegramApi::scrub("cURL error for https://api.telegram.org/bot{$t}/sendMessage", $t))->toBe('cURL error for https://api.telegram.org/bot[token]/sendMessage');
    expect(TelegramApi::scrub('other bot987654321:ZZZZZZZZZZZZZZZZZZZZZZZZZZ failed', ''))->toBe('other bot[token] failed');
});

it('normalizer maps text, caption, document, callback', function () {
    $m = TelegramNormalizer::normalize(['update_id' => 1, 'message' => ['text' => 'hi', 'from' => ['id' => 7, 'username' => 'u'], 'chat' => ['id' => 7, 'type' => 'private']]]);
    expect($m->kind)->toBe(InboundMessage::TEXT)->and($m->fromId)->toBe('7')->and($m->privateChat)->toBeTrue()->and($m->username)->toBe('u');
    $d = TelegramNormalizer::normalize(['update_id' => 2, 'message' => ['caption' => 'cap', 'document' => ['file_id' => 'F', 'file_name' => 'a.txt', 'file_size' => 9], 'from' => ['id' => 7], 'chat' => ['id' => 7, 'type' => 'private']]]);
    expect($d->kind)->toBe(InboundMessage::DOCUMENT)->and($d->text)->toBe('cap')->and($d->fileSize)->toBe(9);
    $c = TelegramNormalizer::normalize(['update_id' => 3, 'callback_query' => ['id' => 'q', 'data' => 'ap:x', 'from' => ['id' => 7], 'message' => ['chat' => ['id' => 7, 'type' => 'private']]]]);
    expect($c->kind)->toBe(InboundMessage::CALLBACK)->and($c->callbackData)->toBe('ap:x');
    expect(TelegramNormalizer::normalize(['update_id' => -1]))->toBeNull();
});

it('inbound message survives queue serialisation', function () {
    $m = new InboundMessage(5, InboundMessage::DOCUMENT, '7', '7', 'u', false, true, 'cap', 'F', 'a.txt', 'text/plain', 9);
    expect(InboundMessage::fromArray(json_decode(json_encode($m->toArray()), true))->toArray())->toBe($m->toArray());
    expect(InboundMessage::fromArray($m->toArray())->fileName)->toBe('a.txt');
});
