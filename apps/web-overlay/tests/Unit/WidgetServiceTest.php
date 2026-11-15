<?php

use App\Inbox\InboxText;
use App\Inbox\WidgetService;
use Tests\Support\FakeInboxRepo;

const WKEY = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
const WORIGIN = 'https://shop.example.com';

function widget(?FakeInboxRepo $r = null): array
{
    $r ??= new FakeInboxRepo;
    $GLOBALS['tenantsSeen'] = [];
    $svc = new WidgetService($r, function (string $t, Closure $f) {
        $GLOBALS['tenantsSeen'][] = $t;

        return $f();
    });

    return [$svc, $r];
}

it('refuses unknown keys, malformed keys, other kinds and unlisted or missing origins', function () {
    [$s, $r] = widget();
    expect($s->start('nope', WORIGIN, null, null)[0])->toBe(404)->and($s->start(str_repeat('c', 32), WORIGIN, null, null)[0])->toBe(404);
    $r->channel['kind'] = 'telegram';
    expect($s->start(WKEY, WORIGIN, null, null)[0])->toBe(404);
    $r->channel['kind'] = 'web_widget';
    $e = $s->start(WKEY, 'https://evil.example.com', null, null);
    expect($e[0])->toBe(403)->and($e[2])->toBeNull();
    expect($s->start(WKEY, null, null, null)[0])->toBe(403);
    expect($s->start(WKEY, 'https://shop.example.com.evil.com', null, null)[0])->toBe(403);
    $r->channel['allowed_origins'] = [];
    expect($s->start(WKEY, WORIGIN, null, null)[0])->toBe(403);
});

it('a paused channel says so but still answers CORS for the allowed origin', function () {
    [$s, $r] = widget();
    $r->channel['status'] = 'paused';
    $x = $s->start(WKEY, WORIGIN, null, null);
    expect($x[0])->toBe(503)->and($x[2])->toBe(WORIGIN);
});

it('a new visitor gets an id and a secret token; only the hash is stored', function () {
    [$s, $r] = widget($f = new FakeInboxRepo);
    [$st, $b, $o] = $s->start(WKEY, WORIGIN, null, null);
    expect($st)->toBe(200)->and($o)->toBe(WORIGIN)->and($b['greeting'])->toBe('হ্যালো')->and($b['messages'])->toBe([])
        ->and(WidgetService::validVisitor($b['visitor']))->toBeTrue()->and(WidgetService::validToken($b['token']))->toBeTrue();
    expect($f->visitors[$b['visitor']]['hash'])->toBe(hash('sha256', $b['token']))->and($f->visitors[$b['visitor']]['hash'] === $b['token'])->toBeFalse();
    expect($GLOBALS['tenantsSeen'])->toBe(['T']);
});

it('a returning visitor keeps their identity and sees staff replies, never failed ones', function () {
    [$s, $r] = widget();
    $a = $s->start(WKEY, WORIGIN, null, null)[1];
    $conv = $r->visitors[$a['visitor']]['conv'];
    $r->send($conv, 'U1', 'staff', 'আসছি');
    $r->send($conv, null, 'agent', 'bot reply');
    $r->messages[] = ['seq' => 99, 'conv' => $conv, 'direction' => 'out', 'sender' => 'staff', 'user_id' => 'U', 'body' => 'broken', 'status' => 'failed', 'error' => 'x', 'created_at' => 'now'];
    $b = $s->start(WKEY, WORIGIN, $a['visitor'], $a['token']);
    expect($b[1]['visitor'])->toBe($a['visitor'])->and(array_column($b[1]['messages'], 'text'))->toBe(['আসছি', 'bot reply'])->and(array_column($b[1]['messages'], 'from'))->toBe(['staff', 'bot']);
    expect(array_keys($b[1]['messages'][0]))->toBe(['seq', 'from', 'text', 'at']);                 // no user ids leak
    $wrong = $s->start(WKEY, WORIGIN, $a['visitor'], str_repeat('0', 32));
    expect($wrong[1]['visitor'] === $a['visitor'])->toBeFalse();                                   // wrong token -> treated as a stranger, gets a fresh identity
});

it('post: needs a valid visitor + token, dedupes retries by client id, enforces limits', function () {
    [$s, $r] = widget();
    $a = $s->start(WKEY, WORIGIN, null, null)[1];
    expect($s->post(WKEY, WORIGIN, $a['visitor'], ($a['token'][0] === 'f' ? '0' : 'f').substr($a['token'], 1), 'hi', null)[0])->toBe(401);
    expect($s->post(WKEY, WORIGIN, 'bogus', $a['token'], 'hi', null)[0])->toBe(401);
    expect($s->post(WKEY, WORIGIN, $a['visitor'], $a['token'], 'hi', 'bad id!')[0])->toBe(422);
    expect($s->post(WKEY, WORIGIN, $a['visitor'], $a['token'], str_repeat('x', 2001), null)[0])->toBe(422);
    $ok = $s->post(WKEY, WORIGIN, $a['visitor'], $a['token'], 'price?', 'c-1');
    $dup = $s->post(WKEY, WORIGIN, $a['visitor'], $a['token'], 'price?', 'c-1');
    expect($ok[1])->toBe(['ok' => true, 'duplicate' => false])->and($dup[1]['duplicate'])->toBeTrue()->and($r->received)->toHaveCount(1);
    $r->nextResult = 'rate_limited';
    expect($s->post(WKEY, WORIGIN, $a['visitor'], $a['token'], 'again', 'c-2')[0])->toBe(429);
    $r->nextResult = 'channel_paused';
    expect($s->post(WKEY, WORIGIN, $a['visitor'], $a['token'], 'again', 'c-3')[0])->toBe(503);
});

it('post from an unlisted origin never reaches storage', function () {
    [$s, $r] = widget();
    $a = $s->start(WKEY, WORIGIN, null, null)[1];
    expect($s->post(WKEY, 'https://evil.example.com', $a['visitor'], $a['token'], 'hi', null)[0])->toBe(403)->and($r->received)->toBe([]);
});

it('poll returns only newer replies and rejects strangers', function () {
    [$s, $r] = widget();
    $a = $s->start(WKEY, WORIGIN, null, null)[1];
    $conv = $r->visitors[$a['visitor']]['conv'];
    $r->send($conv, 'U', 'staff', 'one');
    $r->send($conv, 'U', 'staff', 'two');
    $p = $s->poll(WKEY, WORIGIN, $a['visitor'], $a['token'], 1);
    expect(array_column($p[1]['messages'], 'text'))->toBe(['two']);
    expect($s->poll(WKEY, WORIGIN, $a['visitor'], str_repeat('1', 32), 0)[0])->toBe(401);
    expect($s->poll(WKEY, WORIGIN, null, null, 0)[0])->toBe(401);
});

it('a flooded channel refuses new visitors with 429', function () {
    [$s, $r] = widget();
    $r->limited = true;
    expect($s->start(WKEY, WORIGIN, null, null)[0])->toBe(429);
});

it('staff-side texts: window rule is explained, origins are parsed strictly', function () {
    expect(InboxText::sendError('window_closed'))->toContain('২৪ ঘণ্টা')->and(InboxText::sendError('sent'))->toBeNull()->and(InboxText::sendError('queued'))->toBeNull()->and(InboxText::sendError('emergency'))->toContain('Urgent');
    expect(InboxText::origins("https://Shop.example.com/\nhttp://localhost:3000, https://shop.example.com"))->toBe([['https://shop.example.com', 'http://localhost:3000'], null]);
    expect(InboxText::origins('http://shop.example.com')[1])->toContain('ঠিক নয়');
    expect(InboxText::origins('https://a.com/path')[1])->toContain('ঠিক নয়');
    expect(InboxText::origins('*')[1])->toContain('ঠিক নয়');
    expect(InboxText::origins('')[0])->toBe([]);
});
