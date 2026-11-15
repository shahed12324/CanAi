<?php

use App\Messaging\GatewayHandler;
use App\Messaging\InboundMessage;
use App\Messaging\LinkCode;
use App\Messaging\TelegramNormalizer;
use Tests\Support\FakeApprovals;
use Tests\Support\FakeBridge;
use Tests\Support\FakeChannelStore;
use Tests\Support\FakeLimiter;
use Tests\Support\FakeSender;

const ACC = '01ACCOUNT00000000000000000';

function gw(): array
{
    $s = new FakeChannelStore;
    $snd = new FakeSender;
    $b = new FakeBridge;
    $l = new FakeLimiter;
    $a = new FakeApprovals;

    return [new GatewayHandler($s, $snd, $b, $l, $a), $s, $snd, $b, $l, $a];
}

function msg(int $id, string $from, string $text, array $over = []): InboundMessage
{
    return TelegramNormalizer::normalize(['update_id' => $id, 'message' => array_replace_recursive([
        'text' => $text, 'from' => ['id' => (int) $from, 'is_bot' => false, 'username' => 'bob'], 'chat' => ['id' => (int) $from, 'type' => 'private'],
    ], $over)]);
}

function linked(FakeChannelStore $s, string $ext = '111', ?string $conv = null): void
{
    $s->links[$ext] = ['link_id' => 'LNK'.$ext, 'tenant_id' => 'TEN1', 'user_id' => 'USR1', 'chat_id' => $ext, 'conversation_id' => $conv];
}

it('ignores unknown senders silently', function () {
    [$h, $s, $snd, $b] = gw();
    $h->handle(ACC, msg(1, '999', 'hello there'));
    expect($snd->sent)->toHaveCount(0)->and($b->calls)->toHaveCount(0)->and($s->events[1]['outcome'])->toBe('ignored')->and($s->events[1]['code'])->toBe('unknown_sender');
});

it('shows only static how-to to unknown sender on /start', function () {
    [$h, $s, $snd, $b] = gw();
    $h->handle(ACC, msg(2, '999', '/start'));
    expect($snd->sent)->toHaveCount(1)->and($snd->sent[0]['text'])->toBe(GatewayHandler::HOWTO)->and($b->calls)->toHaveCount(0);
});

it('links with a valid code, once', function () {
    [$h, $s, $snd, $b] = gw();
    $s->codes[LinkCode::hash('ABCD2345')] = ['tenant' => 'TEN1', 'user' => 'USR1', 'used' => false];
    $h->handle(ACC, msg(3, '555', '/link abcd-2345'));
    expect($s->links)->toHaveCount(1)->and($s->events[3]['code'])->toBe('linked')->and($snd->sent[0]['text'])->toContain('connection was successful');
    $h->handle(ACC, msg(4, '556', '/start ABCD2345'));       // same code from another identity
    expect($s->links)->toHaveCount(1)->and($s->events[4]['code'])->toBe('link_failed');
});

it('deep link /start CODE works', function () {
    [$h, $s] = gw();
    $s->codes[LinkCode::hash('ABCD2345')] = ['tenant' => 'TEN1', 'user' => 'USR1', 'used' => false];
    $h->handle(ACC, msg(5, '777', '/start@JarvisBot ABCD2345'));
    expect($s->links)->toHaveCount(1);
});

it('rate limits wrong code guesses per identity', function () {
    [$h, $s, $snd, $b, $l] = gw();
    for ($i = 1; $i <= 5; $i++) {
        $h->handle(ACC, msg(10 + $i, '888', '/link WRONGCO'.$i));
    }
    $s->codes[LinkCode::hash('ABCD2345')] = ['tenant' => 'TEN1', 'user' => 'USR1', 'used' => false];
    $h->handle(ACC, msg(20, '888', '/link ABCD2345'));       // even the right code is refused while limited
    expect($s->links)->toHaveCount(0)->and($s->events[20]['code'])->toBe('link_rate_limited');
});

it('malformed code never reaches the database', function () {
    [$h, $s] = gw();
    $h->handle(ACC, msg(30, '888', "/link ' OR 1=1 --"));
    expect($s->links)->toHaveCount(0)->and($s->events[30]['code'])->toBe('link_failed');
});

it('answers a linked user through the chat bridge and remembers the conversation', function () {
    [$h, $s, $snd, $b] = gw();
    linked($s);
    $h->handle(ACC, msg(40, '111', 'কেমন আছো?'));
    expect($b->calls[0]['tenantId'])->toBe('TEN1')->and($b->calls[0]['userId'])->toBe('USR1')->and($b->calls[0]['conversationId'])->toBeNull()
        ->and($s->links['111']['conversation_id'])->toBe('CONV1')->and($snd->sent[0]['text'])->toContain('Replies')->and($snd->sent[0]['text'])->toContain('Credits: 3')->and($snd->typing)->toBe(1);
    $h->handle(ACC, msg(41, '111', 'আরেকটা'));
    expect($b->calls[1]['conversationId'])->toBe('CONV1');
});

it('/new starts a fresh conversation and /unlink revokes', function () {
    [$h, $s, $snd, $b] = gw();
    linked($s, '111', 'CONV9');
    $h->handle(ACC, msg(50, '111', '/new'));
    expect($s->links['111']['conversation_id'])->toBeNull();
    $h->handle(ACC, msg(51, '111', '/unlink'));
    expect($s->revoked)->toBe(['LNK111']);
    $h->handle(ACC, msg(52, '111', 'hello'));
    expect($b->calls)->toHaveCount(0)->and($s->events[52]['code'])->toBe('unknown_sender');
});

it('ignores bots, groups and edited messages', function () {
    [$h, $s, $snd, $b] = gw();
    linked($s);
    $h->handle(ACC, msg(60, '111', 'hi', ['from' => ['is_bot' => true]]));
    $h->handle(ACC, msg(61, '111', 'hi', ['chat' => ['type' => 'supergroup', 'id' => -100]]));
    $h->handle(ACC, TelegramNormalizer::normalize(['update_id' => 62, 'edited_message' => ['text' => 'x']]));
    expect($b->calls)->toHaveCount(0)->and($snd->sent)->toHaveCount(0)
        ->and($s->events[60]['code'])->toBe('bot_sender')->and($s->events[61]['code'])->toBe('not_private_chat')->and($s->events[62]['code'])->toBe('unsupported_update');
});

it('accepts a small utf-8 text document and passes it as attachment', function () {
    [$h, $s, $snd, $b] = gw();
    linked($s);
    $snd->fileBytes = "মাল,দাম\nচাল,৫০";
    $h->handle(ACC, msg(70, '111', '', ['text' => null, 'caption' => 'সারাংশ দাও', 'document' => ['file_id' => 'F1', 'file_name' => 'data.csv', 'mime_type' => 'text/csv', 'file_size' => 40]]));
    expect($b->calls)->toHaveCount(1)->and($b->calls[0]['attachments'][0]['filename'])->toBe('data.csv')->and($b->calls[0]['text'])->toBe('সারাংশ দাও');
});

it('rejects wrong extension, big and binary documents', function () {
    [$h, $s, $snd, $b] = gw();
    linked($s);
    $doc = fn ($name, $size) => ['text' => null, 'document' => ['file_id' => 'F', 'file_name' => $name, 'file_size' => $size]];
    $h->handle(ACC, msg(80, '111', '', $doc('virus.exe', 100)));
    $h->handle(ACC, msg(81, '111', '', $doc('big.txt', 999999)));
    $snd->fileBytes = "ab\0cd";
    $h->handle(ACC, msg(82, '111', '', $doc('bin.txt', 5)));
    $snd->fileBytes = "\xff\xfe\xfa";
    $h->handle(ACC, msg(83, '111', '', $doc('latin.txt', 3)));
    expect($b->calls)->toHaveCount(0)->and($s->events[80]['code'])->toBe('file_rejected')->and($s->events[81]['code'])->toBe('file_rejected')
        ->and($s->events[82]['code'])->toBe('file_rejected')->and($s->events[83]['code'])->toBe('file_rejected');
});

it('photo and voice get an honest not-yet message and cost nothing', function () {
    [$h, $s, $snd, $b] = gw();
    linked($s);
    $h->handle(ACC, TelegramNormalizer::normalize(['update_id' => 90, 'message' => ['photo' => [['file_id' => 'x']], 'from' => ['id' => 111], 'chat' => ['id' => 111, 'type' => 'private']]]));
    $h->handle(ACC, TelegramNormalizer::normalize(['update_id' => 91, 'message' => ['voice' => ['file_id' => 'x'], 'from' => ['id' => 111], 'chat' => ['id' => 111, 'type' => 'private']]]));
    expect($b->calls)->toHaveCount(0)->and($snd->sent)->toHaveCount(2)->and($s->events[90]['code'])->toBe('unsupported_media');
});

it('long answers are split under the telegram limit', function () {
    [$h, $s, $snd, $b] = gw();
    linked($s);
    $b->reply['text'] = str_repeat("এটি একটি বাক্য। ", 800);
    $h->handle(ACC, msg(100, '111', 'লম্বা'));
    expect(count($snd->sent) > 1)->toBeTrue();
    foreach ($snd->sent as $p) {
        expect(mb_strlen($p['text']) <= 4096)->toBeTrue();
    }
});

it('chat failure with no text reports an error and is marked error', function () {
    [$h, $s, $snd, $b] = gw();
    linked($s);
    $b->reply = ['conversation_id' => null, 'text' => '', 'error' => 'পর্যাপ্ত ক্রেডিট নেই।', 'credits' => null];
    $h->handle(ACC, msg(110, '111', 'Question'));
    expect($snd->sent[0]['text'])->toBe('পর্যাপ্ত ক্রেডিট নেই।')->and($s->events[110]['outcome'])->toBe('error');
});

it('rate limits a chatty linked user', function () {
    [$h, $s, $snd, $b] = gw();
    linked($s);
    for ($i = 0; $i < 22; $i++) {
        $h->handle(ACC, msg(200 + $i, '111', 'x'.$i));
    }
    expect($b->calls)->toHaveCount(20)->and($s->events[221]['code'])->toBe('rate_limited');
});

it('approval buttons: valid payload reaches the decider, junk does not, unknown user is ignored', function () {
    [$h, $s, $snd, $b, $l, $a] = gw();
    linked($s);
    $cb = fn ($id, $data, $from = 111) => TelegramNormalizer::normalize(['update_id' => $id, 'callback_query' => ['id' => 'cq'.$id, 'data' => $data, 'from' => ['id' => $from], 'message' => ['chat' => ['id' => $from, 'type' => 'private']]]]);
    $h->handle(ACC, $cb(300, 'ap:01ARZ3NDEKTSV4RRFFQ69G5FAV:a'));
    $h->handle(ACC, $cb(301, 'ap:short:a'));
    $h->handle(ACC, $cb(302, 'ap:01ARZ3NDEKTSV4RRFFQ69G5FAV:a', 424242));
    expect($a->calls)->toHaveCount(1)->and($a->calls[0][3])->toBeTrue()->and($snd->toasts[0])->toBe('Allowed')->and($snd->toasts[1])->toContain('no longer valid')->and($s->events[302]['code'])->toBe('unknown_sender');
});

it('a crash while answering still finishes the event and never throws', function () {
    [$h, $s, $snd, $b] = gw();
    linked($s);
    $snd->throwOnSend = true;
    $h->handle(ACC, msg(400, '111', 'hi'));
    expect($s->events[400]['outcome'])->toBe('error')->and($s->events[400]['code'])->toBe('exception');
});

it('normalizer survives garbage', function () {
    foreach ([[], ['update_id' => 'x'], ['update_id' => 5, 'message' => 'str'], ['update_id' => 6, 'message' => ['from' => 'x', 'chat' => [], 'text' => ['a']]],
        ['update_id' => 7, 'callback_query' => 5]] as $u) {
        $r = TelegramNormalizer::normalize($u);
        expect($r === null || $r instanceof InboundMessage)->toBeTrue();
    }
});

it('link codes: alphabet, normalisation, hashing', function () {
    $c = LinkCode::generate();
    expect(strlen($c))->toBe(8)->and(LinkCode::normalize(LinkCode::pretty($c)))->toBe($c)->and(LinkCode::normalize('abcd 2345'))->toBe('ABCD2345')
        ->and(LinkCode::normalize('ABCD234'))->toBe('')->and(LinkCode::normalize('ABCD0OIL'))->toBe('')->and(strlen(LinkCode::hash($c)))->toBe(64);
});

it('command parser', function () {
    expect(GatewayHandler::command('/link@Bot abcd'))->toBe(['link', 'abcd'])->and(GatewayHandler::command('hello /link'))->toBeNull()->and(GatewayHandler::command('/NEW'))->toBe(['new', '']);
});
