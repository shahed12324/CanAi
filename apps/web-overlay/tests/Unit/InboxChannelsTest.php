<?php

use App\Inbox\Channels\ChannelAdapter;
use App\Inbox\Channels\ChannelDispatcher;
use App\Inbox\Channels\ChannelException;
use App\Inbox\Channels\InboundParser;
use App\Inbox\Channels\MetaAdapter;
use App\Inbox\Channels\TelegramBotAdapter;
use App\Inbox\Channels\WebhookService;
use App\Inbox\Channels\WebhookVerifier;
use Tests\Support\FakeHttpJson;
use Tests\Support\FakeInboxRepo;

const MKEY = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

// ---------- signatures ----------
it('Meta signature: right secret passes; wrong secret, altered body, missing or malformed header fail', function () {
    $raw = '{"a":1}';
    $h = 'sha256='.hash_hmac('sha256', $raw, 'appsecret');
    expect(WebhookVerifier::meta($raw, $h, 'appsecret'))->toBeTrue();
    expect(WebhookVerifier::meta($raw, $h, 'other'))->toBeFalse()->and(WebhookVerifier::meta($raw.' ', $h, 'appsecret'))->toBeFalse()
        ->and(WebhookVerifier::meta($raw, null, 'appsecret'))->toBeFalse()->and(WebhookVerifier::meta($raw, substr($h, 7), 'appsecret'))->toBeFalse()
        ->and(WebhookVerifier::meta($raw, $h, ''))->toBeFalse();
});

it('Telegram secret header and the Meta handshake', function () {
    expect(WebhookVerifier::telegram('s3cret', 's3cret'))->toBeTrue()->and(WebhookVerifier::telegram('x', 's3cret'))->toBeFalse()->and(WebhookVerifier::telegram(null, 's3cret'))->toBeFalse()->and(WebhookVerifier::telegram('', ''))->toBeFalse();
    $t = WebhookVerifier::verifyToken(MKEY);
    expect(WebhookVerifier::challenge('subscribe', $t, '12345', MKEY))->toBe('12345')->and(WebhookVerifier::challenge('subscribe', 'nope', '12345', MKEY))->toBeNull()
        ->and(WebhookVerifier::challenge('unsubscribe', $t, '12345', MKEY))->toBeNull()->and(WebhookVerifier::challenge('subscribe', $t, '<script>', MKEY))->toBeNull()
        ->and(WebhookVerifier::challenge('subscribe', $t, null, MKEY))->toBeNull();
    expect(WebhookVerifier::verifyToken(MKEY) === WebhookVerifier::verifyToken(str_repeat('b', 32)))->toBeFalse();
});

// ---------- parsing ----------
it('parses Messenger/Instagram messages, skips echoes, postbacks and other pages', function () {
    $p = ['entry' => [
        ['id' => 'PAGE1', 'messaging' => [
            ['sender' => ['id' => 'U1'], 'message' => ['mid' => 'm1', 'text' => 'দাম কত?']],
            ['sender' => ['id' => 'PAGE1'], 'message' => ['mid' => 'm2', 'text' => 'echo', 'is_echo' => true]],
            ['sender' => ['id' => 'U2'], 'postback' => ['payload' => 'x']],
            ['sender' => ['id' => 'U3'], 'message' => ['mid' => 'm3', 'attachments' => [['type' => 'image']]]],
        ]],
        ['id' => 'OTHER', 'messaging' => [['sender' => ['id' => 'U9'], 'message' => ['mid' => 'm9', 'text' => 'not ours']]]],
    ]];
    $r = InboundParser::meta('messenger', $p, 'PAGE1');
    expect($r)->toHaveCount(2)->and($r[0])->toBe(['contact' => 'U1', 'name' => null, 'text' => 'দাম কত?', 'mid' => 'm1'])->and($r[1]['text'])->toContain('ছবি');
});

it('parses WhatsApp text and media for our phone number only', function () {
    $v = fn ($pn, $msgs) => ['entry' => [['id' => 'W', 'changes' => [['field' => 'messages', 'value' => ['metadata' => ['phone_number_id' => $pn], 'contacts' => [['wa_id' => '8801711111111', 'profile' => ['name' => 'Rina']]], 'messages' => $msgs]]]]]];
    $r = InboundParser::meta('whatsapp', $v('PN1', [['from' => '8801711111111', 'id' => 'wamid.1', 'type' => 'text', 'text' => ['body' => 'hi']], ['from' => '8801711111111', 'id' => 'wamid.2', 'type' => 'audio'], ['from' => 'bad<>', 'id' => 'wamid.3', 'type' => 'text', 'text' => ['body' => 'x']]]), 'PN1');
    expect($r)->toHaveCount(2)->and($r[0]['name'])->toBe('Rina')->and($r[0]['text'])->toBe('hi')->and($r[1]['text'])->toContain('Voice');
    expect(InboundParser::meta('whatsapp', $v('PN2', [['from' => '8801711111111', 'id' => 'w', 'type' => 'text', 'text' => ['body' => 'x']]]), 'PN1'))->toBe([]);
    expect(InboundParser::meta('whatsapp', ['entry' => 'junk'], 'PN1'))->toBe([])->and(InboundParser::meta('messenger', [], 'P'))->toBe([]);
});

it('parses Telegram private chats only', function () {
    $u = ['update_id' => 1, 'message' => ['message_id' => 5, 'from' => ['id' => 7, 'first_name' => 'আলী', 'last_name' => 'খান'], 'chat' => ['id' => 7, 'type' => 'private'], 'text' => 'hello']];
    expect(InboundParser::telegram($u))->toBe([['contact' => '7', 'name' => 'আলী খান', 'text' => 'hello', 'mid' => '7:5']]);
    $g = $u;
    $g['message']['chat']['type'] = 'group';
    $b = $u;
    $b['message']['from']['is_bot'] = true;
    expect(InboundParser::telegram($g))->toBe([])->and(InboundParser::telegram($b))->toBe([])->and(InboundParser::telegram(['edited_message' => []]))->toBe([]);
    $p = $u;
    unset($p['message']['text']);
    $p['message']['photo'] = [[]];
    expect(InboundParser::telegram($p)[0]['text'])->toContain('ছবি');
});

// ---------- webhook service ----------
function hook(string $kind, array $creds, ?FakeInboxRepo $r = null): array
{
    $r ??= new FakeInboxRepo;
    $r->channel = ['id' => 'CH', 'tenant_id' => 'T', 'kind' => $kind, 'status' => 'active', 'allowed_origins' => [], 'greeting' => null, 'name' => 'P', 'external_ref' => $kind === 'whatsapp' ? 'PN1' : 'PAGE1'];
    $GLOBALS['hookTenant'] = [];

    return [new WebhookService($r, fn () => $creds, function ($t, Closure $f) {
        $GLOBALS['hookTenant'][] = $t;

        return $f();
    }), $r];
}

it('webhook: bad or missing signature is refused and nothing is stored', function () {
    [$w, $r] = hook('messenger', ['app_secret' => 'sec']);
    $raw = json_encode(['entry' => [['id' => 'PAGE1', 'messaging' => [['sender' => ['id' => 'U1'], 'message' => ['mid' => 'm1', 'text' => 'hi']]]]]]);
    expect($w->receive(MKEY, $raw, [])[0])->toBe(403)->and($w->receive(MKEY, $raw, ['x-hub-signature-256' => 'sha256='.hash_hmac('sha256', $raw, 'wrong')])[0])->toBe(403);
    expect($r->received)->toBe([]);
    $ok = $w->receive(MKEY, $raw, ['x-hub-signature-256' => 'sha256='.hash_hmac('sha256', $raw, 'sec')]);
    expect($ok[0])->toBe(200)->and($r->received)->toHaveCount(1)->and($r->received[0]['body'])->toBe('hi')->and($r->received[0]['ext'])->toBe('m1')->and($GLOBALS['hookTenant'])->toBe(['T', 'T', 'T']);
});

it('webhook: unknown key, widget channel, malformed json, paused channel, missing secret', function () {
    [$w, $r] = hook('messenger', ['app_secret' => 'sec']);
    $sig = fn ($raw) => ['x-hub-signature-256' => 'sha256='.hash_hmac('sha256', $raw, 'sec')];
    expect($w->receive('zz', '{}', [])[0])->toBe(404)->and($w->receive(str_repeat('c', 32), '{}', [])[0])->toBe(404);
    expect($w->receive(MKEY, 'not json', $sig('not json'))[0])->toBe(400)->and($w->receive(MKEY, '"str"', $sig('"str"'))[0])->toBe(400);
    [$w2] = hook('web_widget', []);
    expect($w2->receive(MKEY, '{}', [])[0])->toBe(404);
    [$w3, $r3] = hook('messenger', []);
    expect($w3->receive(MKEY, '{}', $sig('{}'))[0])->toBe(403);
    [$w4, $r4] = hook('messenger', ['app_secret' => 'sec']);
    $r4->channel['status'] = 'paused';
    expect($w4->receive(MKEY, '{}', $sig('{}')))->toBe([200, 'paused'])->and($r4->received)->toBe([]);
});

it('webhook: Telegram checks the secret header; WhatsApp stores name; retries do not duplicate', function () {
    [$w, $r] = hook('telegram', ['webhook_secret' => 'tgs']);
    $raw = json_encode(['message' => ['message_id' => 5, 'from' => ['id' => 7, 'first_name' => 'A'], 'chat' => ['id' => 7, 'type' => 'private'], 'text' => 'hi']]);
    expect($w->receive(MKEY, $raw, [])[0])->toBe(403)->and($w->receive(MKEY, $raw, ['x-telegram-bot-api-secret-token' => 'bad'])[0])->toBe(403);
    $h = ['x-telegram-bot-api-secret-token' => 'tgs'];
    $w->receive(MKEY, $raw, $h);
    $w->receive(MKEY, $raw, $h);
    expect($r->received)->toHaveCount(1)->and($r->contacts['7'])->toBe('A');
    [$ww, $rw] = hook('whatsapp', ['app_secret' => 'sec']);
    $wa = json_encode(['entry' => [['id' => 'W', 'changes' => [['field' => 'messages', 'value' => ['metadata' => ['phone_number_id' => 'PN1'], 'contacts' => [['wa_id' => '8801711111111', 'profile' => ['name' => 'Rina']]], 'messages' => [['from' => '8801711111111', 'id' => 'w1', 'type' => 'text', 'text' => ['body' => 'hi']]]]]]]]]);
    $ww->receive(MKEY, $wa, ['x-hub-signature-256' => 'sha256='.hash_hmac('sha256', $wa, 'sec')]);
    expect($rw->contacts['8801711111111'])->toBe('Rina');
});

it('webhook: handshake works for Meta kinds only', function () {
    [$w] = hook('messenger', []);
    expect($w->handshake(MKEY, 'subscribe', WebhookVerifier::verifyToken(MKEY), '777'))->toBe([200, '777'])->and($w->handshake(MKEY, 'subscribe', 'bad', '777')[0])->toBe(403);
    [$t] = hook('telegram', []);
    expect($t->handshake(MKEY, 'subscribe', WebhookVerifier::verifyToken(MKEY), '777')[0])->toBe(404);
});

// ---------- adapters ----------
it('Meta adapter: Messenger request shape; token in header not URL; 4xx permanent; 5xx/429 retryable', function () {
    $h = new FakeHttpJson;
    $a = new MetaAdapter($h, 'messenger');
    $h->queue[] = ['status' => 200, 'json' => ['message_id' => 'mid.1']];
    expect($a->send(['access_token' => 'TOK'], 'PAGE1', 'U1', 'হ্যালো'))->toBe('mid.1');
    $c = $h->calls[0];
    expect($c['url'])->toBe('https://graph.facebook.com/v21.0/me/messages')->and(str_contains($c['url'], 'TOK'))->toBeFalse()->and($c['headers']['Authorization'])->toBe('Bearer TOK')
        ->and(json_decode($c['body'], true))->toBe(['recipient' => ['id' => 'U1'], 'messaging_type' => 'RESPONSE', 'message' => ['text' => 'হ্যালো']]);
    foreach ([[400, true], [403, true], [429, false], [500, false]] as [$st, $perm]) {
        $h->queue[] = ['status' => $st, 'json' => ['error' => ['message' => 'nope']]];
        try {
            $a->send(['access_token' => 'TOK'], 'PAGE1', 'U1', 'x');
            throw new AssertionError('should throw');
        } catch (ChannelException $e) {
            expect($e->permanent)->toBe($perm);
        }
    }
    expect(fn () => $a->send([], 'PAGE1', 'U1', 'x'))->toThrow(ChannelException::class);
    expect(fn () => $a->send(['access_token' => 'T'], 'PAGE1', 'U 1;drop', 'x'))->toThrow(ChannelException::class);
});

it('Meta adapter: WhatsApp request shape and limits', function () {
    $h = new FakeHttpJson;
    $a = new MetaAdapter($h, 'whatsapp', 'v22.0');
    $h->queue[] = ['status' => 200, 'json' => ['messages' => [['id' => 'wamid.9']]]];
    expect($a->send(['access_token' => 'T'], '123456789', '8801711111111', 'hi'))->toBe('wamid.9');
    expect($h->calls[0]['url'])->toBe('https://graph.facebook.com/v22.0/123456789/messages')->and(json_decode($h->calls[0]['body'], true)['to'])->toBe('8801711111111');
    expect((new MetaAdapter($h, 'instagram'))->maxLength())->toBe(1000)->and($a->maxLength())->toBe(4096);
    expect(fn () => $a->send(['access_token' => 'T'], '../x', '8801711111111', 'hi'))->toThrow(ChannelException::class);
    expect(fn () => new MetaAdapter($h, 'telegram'))->toThrow(InvalidArgumentException::class);
});

it('Telegram adapter: sends, validates token shape, never leaks the token in errors', function () {
    $h = new FakeHttpJson;
    $a = new TelegramBotAdapter($h);
    $tok = '123456:ABCdefGhIJKlmnoPQRstuVWXyz012345';
    $h->queue[] = ['status' => 200, 'json' => ['ok' => true, 'result' => ['message_id' => 42]]];
    expect($a->send(['bot_token' => $tok], 'x', '777', 'hi'))->toBe('42')->and($h->calls[0]['url'])->toBe("https://api.telegram.org/bot$tok/sendMessage");
    $h->queue[] = ['status' => 403, 'json' => ['ok' => false, 'description' => "Forbidden: bot $tok was blocked"]];
    try {
        $a->send(['bot_token' => $tok], 'x', '777', 'hi');
        throw new AssertionError('should throw');
    } catch (ChannelException $e) {
        expect($e->permanent)->toBeTrue()->and(str_contains($e->getMessage(), $tok))->toBeFalse();
    }
    expect(fn () => $a->send(['bot_token' => 'bad'], 'x', '777', 'hi'))->toThrow(ChannelException::class);
    expect(fn () => $a->send(['bot_token' => $tok], 'x', 'abc', 'hi'))->toThrow(ChannelException::class);
});

// ---------- dispatcher ----------
final class StubAdapter implements ChannelAdapter
{
    public array $sent = [];

    public ?ChannelException $fail = null;

    public int $failAfter = 0;

    public function maxLength(): int
    {
        return 20;
    }

    public function send(array $creds, string $accountRef, string $to, string $text): string
    {
        if ($this->fail !== null && count($this->sent) >= $this->failAfter) {
            throw $this->fail;
        }
        $this->sent[] = [$to, $text];

        return 'id';
    }
}

function disp(array $ctx, ?StubAdapter $a = null, ?array $creds = ['t' => 1]): array
{
    $r = new FakeInboxRepo;
    $r->ctx = $ctx + ['seq' => 5, 'channel_id' => 'CH', 'kind' => 'telegram', 'external_ref' => 'R', 'to_external' => '777', 'body' => 'hello', 'status' => 'queued', 'attempts' => 0, 'window_ok' => true, 'emergency' => false, 'channel_active' => true];
    $a ??= new StubAdapter;

    return [new ChannelDispatcher($r, fn () => $a, fn () => $creds), $r, $a];
}

it('dispatcher: sends and marks sent; splits long text; skips non-queued', function () {
    [$d, $r, $a] = disp(['body' => 'one two three four five six seven eight']);
    expect($d->dispatch(5))->toBe('sent')->and($r->statuses)->toBe([[5, 'sent', null]]);
    expect(count($a->sent) > 1)->toBeTrue();
    foreach ($a->sent as [$to, $t]) {
        expect(mb_strlen($t))->toBeLessThan(21);
    }
    [$d2, $r2] = disp(['status' => 'sent']);
    expect($d2->dispatch(5))->toBe('skipped')->and($r2->statuses)->toBe([]);
});

it('dispatcher: emergency holds, closed window and paused channel fail, nothing is sent', function () {
    [$d, $r, $a] = disp(['emergency' => true]);
    expect($d->dispatch(5))->toBe('held')->and($r->statuses)->toBe([])->and($a->sent)->toBe([]);
    [$d, $r, $a] = disp(['window_ok' => false, 'kind' => 'whatsapp']);
    expect($d->dispatch(5))->toBe('failed')->and($r->statuses[0][2])->toBe('window_closed')->and($a->sent)->toBe([]);
    [$d, $r, $a] = disp(['channel_active' => false]);
    expect($d->dispatch(5))->toBe('failed')->and($a->sent)->toBe([]);
    [$d, $r, $a] = disp([], null, null);
    expect($d->dispatch(5))->toBe('failed')->and($r->statuses[0][2])->toContain('credentials');
});

it('dispatcher: transient errors retry, permanent fail at once, a half-sent message is never resent', function () {
    $a = new StubAdapter;
    $a->fail = new ChannelException('HTTP 500');
    [$d, $r] = disp([], $a);
    expect($d->dispatch(5))->toBe('retry')->and($r->retries)->toBe([[5, 'HTTP 500']])->and($r->statuses)->toBe([]);
    [$d, $r] = disp([], $a);
    $r->retryResult = 'failed';
    expect($d->dispatch(5))->toBe('failed');
    $b = new StubAdapter;
    $b->fail = new ChannelException('blocked', true);
    [$d, $r] = disp([], $b);
    expect($d->dispatch(5))->toBe('failed')->and($r->statuses[0][1])->toBe('failed')->and($r->retries)->toBe([]);
    $c = new StubAdapter;
    $c->fail = new ChannelException('HTTP 500');
    $c->failAfter = 1;
    [$d, $r] = disp(['body' => 'one two three four five six seven eight'], $c);
    expect($d->dispatch(5))->toBe('failed')->and($r->statuses[0][2])->toContain('partial')->and($r->retries)->toBe([]);
});
