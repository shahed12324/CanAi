<?php

use App\Developer\WebhookSigner;
use App\Developer\Webhooks;
use App\Developer\WebhookUrl;
use Tests\Support\FakeSecrets;
use Tests\Support\FakeWebhookRepo;
use Tests\Support\FakeWebhookSender;

function whsvc(): array
{
    $r = new FakeWebhookRepo;
    $s = new FakeWebhookSender;
    $s->dns['hooks.example.com'] = ['93.184.216.34'];

    return [new Webhooks($r, new FakeSecrets, $s), $r, $s];
}

function whmsg(array $o = []): array
{
    return $o + ['id' => 'D'.str_repeat('0', 25), 'tenant_id' => 'T', 'url' => 'https://hooks.example.com/j', 'cipher' => 'ENC('.strrev('whsec_abc').')', 'version' => 1,
        'payload' => ['event' => 'run.finished', 'run_id' => 'R1', 'status' => 'done', 'spent' => '1.5000'], 'attempts' => 1];
}

// ---------- addresses
it('accepts only public https addresses', function () {
    foreach (['https://hooks.example.com/x', 'https://a.b.co:8443/p?q=1', 'https://93.184.216.34/h', 'https://sub-1.my-site.com.bd/hook'] as $u) {
        expect(WebhookUrl::check($u))->toBe($u);
    }
    foreach ([
        '', 'http://hooks.example.com/x', 'ftp://a.com', 'https://', 'https:///x', 'javascript:alert(1)', 'https://localhost/x', 'https://intranet/x', 'https://a.local/x', 'https://x.internal/x',
        'https://a.lan/x', 'https://127.0.0.1/x', 'https://10.0.0.5/x', 'https://192.168.1.1/x', 'https://172.16.0.1/x', 'https://169.254.169.254/latest', 'https://0.0.0.0/x', 'https://[::1]/x',
        'https://[2001:db8::1]/x', 'https://user:pw@a.com/x', 'https://a.com:80/x', 'https://a.com:22/x', 'https://a.com/x#frag', "https://a.com/x y", "https://a.com/\x00x", 'https://a.com\\@b.com/',
        'https://-a.com/x', 'https://a..com/x', 'https://a.c/x', 'https://' . str_repeat('a', 64) . '.com/x', 'https://a.com/' . str_repeat('p', 487),
    ] as $u) {
        expect(WebhookUrl::check($u))->toBeNull();
    }
    expect(WebhookUrl::check('  https://hooks.example.com/x  '))->toBe('https://hooks.example.com/x');
    expect(strlen(WebhookUrl::check('https://a.com/'.str_repeat('p', 486))))->toBe(500);
});

it('knows public from private addresses', function () {
    foreach (['93.184.216.34', '8.8.8.8', '100.63.255.255', '100.128.0.1', '198.17.255.255', '198.20.0.1', '223.255.255.254'] as $ip) {
        expect(WebhookUrl::isPublicIp($ip))->toBeTrue();
    }
    foreach (['127.0.0.1', '10.1.2.3', '192.168.0.1', '172.20.0.1', '169.254.1.1', '0.0.0.0', '100.64.0.1', '100.127.255.254', '192.0.2.5', '198.18.0.1', '198.51.100.7', '203.0.113.9', '224.0.0.1', '255.255.255.255', '::1', 'fe80::1', 'zz', ''] as $ip) {
        expect(WebhookUrl::isPublicIp($ip))->toBeFalse();
    }
});

// ---------- signing
it('signs "<timestamp>.<body>" with the secret and carries the delivery headers', function () {
    $sig = WebhookSigner::sign('whsec_abc', 1700000000, '{"a":1}');
    expect($sig)->toBe('v1='.hash_hmac('sha256', '1700000000.{"a":1}', 'whsec_abc'));
    expect(WebhookSigner::sign('whsec_abd', 1700000000, '{"a":1}') === $sig)->toBeFalse();
    expect(WebhookSigner::sign('whsec_abc', 1700000001, '{"a":1}') === $sig)->toBeFalse();
    expect(WebhookSigner::sign('whsec_abc', 1700000000, '{"a":2}') === $sig)->toBeFalse();
    $h = WebhookSigner::headers('whsec_abc', 1700000000, '{"a":1}', 'run.finished', 'D1');
    expect($h['X-Jarvis-Signature'])->toBe($sig);
    expect($h['X-Jarvis-Timestamp'])->toBe('1700000000');
    expect($h['X-Jarvis-Event'])->toBe('run.finished');
    expect($h['X-Jarvis-Delivery'])->toBe('D1');
    expect($h['Content-Type'])->toBe('application/json');
});

// ---------- adding
it('stores the secret encrypted, shows it once, and checks name and address first', function () {
    [$w, $r] = whsvc();
    $x = $w->add('T', 'U', 'n8n', 'https://hooks.example.com/x');
    expect($x['status'])->toBe('ok');
    expect(preg_match('/\Awhsec_[0-9a-f]{48}\z/', $x['secret']))->toBe(1);
    expect($r->created[0]['cipher'])->toBe('ENC('.strrev($x['secret']).')');
    expect(str_contains(json_encode($r->created[0]), $x['secret']))->toBeFalse();
    expect($w->add('T', 'U', '  ', 'https://hooks.example.com/x')['status'])->toBe('bad_name');
    expect($w->add('T', 'U', str_repeat('ক', 81), 'https://hooks.example.com/x')['status'])->toBe('bad_name');
    expect($w->add('T', 'U', str_repeat('ক', 80), 'https://hooks.example.com/x')['status'])->toBe('ok');
    expect($w->add('T', 'U', 'a', 'http://hooks.example.com/x')['status'])->toBe('bad_url');
    expect($w->add('T', 'U', 'a', 'https://127.0.0.1/x')['status'])->toBe('bad_url');
    expect(count($r->created))->toBe(2);
    $r->createResult = 'limit';
    expect($w->add('T', 'U', 'a', 'https://hooks.example.com/x')['status'])->toBe('limit');
    $r->createResult = 'duplicate';
    expect($w->add('T', 'U', 'a', 'https://hooks.example.com/x')['status'])->toBe('failed');
});

// ---------- sending
it('sends the signed body to the checked public address and reports success only for a 2xx answer', function () {
    foreach ([[200, true], [204, true], [299, true], [199, false], [301, false], [404, false], [500, false]] as [$code, $ok]) {
        [$w, $r, $s] = whsvc();
        $s->answer = ['code' => $code, 'error' => null];
        $r->queue = [whmsg()];
        $out = $w->work(20, 1700000000);
        expect($r->results[0]['ok'])->toBe($ok);
        expect($r->results[0]['code'])->toBe($code);
        expect($out[$ok ? 'sent' : 'failed'])->toBe(1);
    }
    [$w, $r, $s] = whsvc();
    $r->queue = [whmsg()];
    $w->work(20, 1700000000);
    $p = $s->posts[0];
    expect($p['url'])->toBe('https://hooks.example.com/j');
    expect($p['ip'])->toBe('93.184.216.34');
    expect($p['body'])->toBe('{"event":"run.finished","run_id":"R1","status":"done","spent":"1.5000"}');
    expect($p['headers']['X-Jarvis-Signature'])->toBe(WebhookSigner::sign('whsec_abc', 1700000000, $p['body']));
    expect($p['headers']['X-Jarvis-Delivery'])->toBe('D'.str_repeat('0', 25));
});

it('refuses to send when the name points at a private address, even if only one answer is private', function () {
    foreach ([['10.0.0.5'], ['127.0.0.1'], ['93.184.216.34', '169.254.169.254'], ['169.254.169.254', '93.184.216.34']] as $ips) {
        [$w, $r, $s] = whsvc();
        $s->dns['hooks.example.com'] = $ips;
        $r->queue = [whmsg()];
        $w->work();
        expect($s->posts)->toBe([]);
        expect($r->results[0]['ok'])->toBeFalse();
        expect($r->results[0]['error'])->toBe('points at a private address');
    }
});

it('fails cleanly when the name does not resolve, the stored address is not allowed any more, the secret cannot be read, or the send throws', function () {
    [$w, $r, $s] = whsvc();
    $s->dns = [];
    $r->queue = [whmsg()];
    $w->work();
    expect($r->results[0]['error'])->toBe('name did not resolve');

    [$w, $r, $s] = whsvc();
    $r->queue = [whmsg(['url' => 'https://localhost/x'])];
    $w->work();
    expect($r->results[0]['error'])->toBe('address not allowed');
    expect($s->posts)->toBe([]);

    $r = new FakeWebhookRepo;
    $sec = new FakeSecrets;
    $sec->failDecrypt = true;
    $s = new FakeWebhookSender;
    $s->dns['hooks.example.com'] = ['93.184.216.34'];
    $r->queue = [whmsg()];
    (new Webhooks($r, $sec, $s))->work();
    expect($r->results[0]['error'])->toBe('secret unreadable');
    expect($s->posts)->toBe([]);

    [$w, $r, $s] = whsvc();
    $s->throw = new \RuntimeException('boom');
    $r->queue = [whmsg()];
    $w->work();
    expect($r->results[0]['ok'])->toBeFalse();
    expect($r->results[0]['error'])->toBe('send failed');
});

it('sends to a public IP written in the address without a lookup, and records a transport error without a code', function () {
    [$w, $r, $s] = whsvc();
    $s->answer = ['code' => 0, 'error' => 'timeout'];
    $r->queue = [whmsg(['url' => 'https://93.184.216.34/h'])];
    $w->work();
    expect($s->posts[0]['ip'])->toBe('93.184.216.34');
    expect($r->results[0]['code'])->toBeNull();
    expect($r->results[0]['error'])->toBe('timeout');
});

it('works through the queue within the limit and reports how many were queued', function () {
    [$w, $r] = whsvc();
    $r->enqueued = 3;
    $r->queue = [whmsg(['id' => 'A']), whmsg(['id' => 'B']), whmsg(['id' => 'C'])];
    $out = $w->work(2, 1700000000);
    expect($out)->toBe(['queued' => 3, 'sent' => 2, 'failed' => 0]);
    expect(count($r->queue))->toBe(1);
    expect($w->work(0)['sent'])->toBe(1);            // a limit of 0 still does one
});

// ---------- event choice (Phase 28d)
it('passes only the three known events, de-duplicated, to the repo', function () {
    [$w, $r] = whsvc();
    expect($w->setEvents('T', 'U', 'H', ['run.finished', 'pc.action.pending', 'run.finished']))->toBe('ok');
    expect($r->eventCalls)->toHaveCount(1);
    expect($r->eventCalls[0]['events'])->toBe(['run.finished', 'pc.action.pending']);
    expect([$r->eventCalls[0]['tenantId'], $r->eventCalls[0]['userId'], $r->eventCalls[0]['id']])->toBe(['T', 'U', 'H']);
    expect(Webhooks::EVENTS)->toBe(['run.finished', 'run.waiting_approval', 'pc.action.pending', 'workflow.message']);
});

it('refuses empty, unknown and non-text events before touching the repo', function () {
    [$w, $r] = whsvc();
    foreach ([[], ['nope'], ['run.finished', 'x'], ['RUN.FINISHED'], [1], [true], [0], [['run.finished']], ['run.finished', null]] as $bad) {
        expect($w->setEvents('T', 'U', 'H', $bad))->toBe('bad_input');
    }
    expect($r->eventCalls)->toBe([]);
});

it('wires the events route, button and the SQL function', function () {
    $routes = (string) file_get_contents(__DIR__.'/../../routes/web.php');
    expect(preg_match("#/hooks/\{id\}/events'.*hookEvents.*developer\.hook\.events#", $routes))->toBe(1);
    $view = (string) file_get_contents(__DIR__.'/../../resources/views/developer/index.blade.php');
    expect($view)->toContain("route('developer.hook.events'")->and($view)->toContain('name="events[]"')->and($view)->toContain('pc.action.pending')->and($view)->toContain('run.waiting_approval');
    $ctl = (string) file_get_contents(__DIR__.'/../../app/Http/Controllers/DeveloperController.php');
    expect($ctl)->toContain("'events.*' => ['string', 'in:'")->and($ctl)->toContain('Webhooks::EVENTS')->and($ctl)->toContain("'max:4'");
    $pg = (string) file_get_contents(__DIR__.'/../../app/Developer/PgWebhookRepo.php');
    expect($pg)->toContain('webhook_set_events(?, ?, ?, ?::text[])');
});
