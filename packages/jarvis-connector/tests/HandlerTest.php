<?php

use Jarvis\Connector\ArrayNonceStore;
use Jarvis\Connector\Handler;
use Jarvis\Connector\Signature;
use Jarvis\Connector\Tests\MemoryStore;

const SECRET = 'abcdefabcdefabcdefabcdefabcdefabcdefabcdefabcdef';
const NOW = 1_800_000_000;

function rig(?MemoryStore $s = null, string $secret = SECRET): array
{
    $s ??= new MemoryStore;

    return [new Handler($s, $secret, new ArrayNonceStore, fn () => NOW, 'My Shop', 'BDT'), $s];
}

function call(Handler $h, string $method, string $path, ?array $body = null, array $over = []): array
{
    $raw = $body === null ? '' : json_encode($body);
    $ts = (string) ($over['ts'] ?? NOW);
    $nonce = $over['nonce'] ?? bin2hex(random_bytes(12));
    $sig = $over['sig'] ?? Signature::sign($over['secret'] ?? SECRET, $ts, $nonce, $method, $path, $raw);

    return $h->handle($method, $path, $over['raw'] ?? $raw, ['x-jarvis-timestamp' => $ts, 'x-jarvis-nonce' => $nonce, 'x-jarvis-signature' => $sig]);
}

const P = '/jarvis-connector/v1';
const PRODUCT = ['title' => 'Tee', 'description' => 'Soft', 'price_minor' => 50000, 'sale_price_minor' => 40000, 'sku' => 'T1', 'images' => ['https://x.test/a.jpg'], 'variants' => [['sku' => null, 'options' => ['Size' => 'M'], 'stock' => 3]]];

it('answers ping to a correctly signed request only', function () {
    [$h] = rig();
    [$st, $j] = call($h, 'GET', P.'/ping');
    expect($st)->toBe(200)->and($j['name'])->toBe('My Shop')->and($j['capabilities'])->toContain('stock');
});

it('rejects wrong secret, tampered path, tampered body, stale clock, missing headers and replays', function () {
    [$h] = rig();
    expect(call($h, 'GET', P.'/ping', null, ['secret' => 'x'.SECRET])[0])->toBe(401);
    $nonce = 'n0123456789abcdefn0123456789abcdef';
    $sig = Signature::sign(SECRET, (string) NOW, $nonce, 'GET', P.'/ping', '');
    expect($h->handle('GET', P.'/products', '', ['x-jarvis-timestamp' => (string) NOW, 'x-jarvis-nonce' => $nonce, 'x-jarvis-signature' => $sig])[1]['error'])->toBe('bad_signature');
    $body = json_encode(PRODUCT);
    $sig2 = Signature::sign(SECRET, (string) NOW, $nonce, 'POST', P.'/products', $body);
    expect($h->handle('POST', P.'/products', $body.' ', ['x-jarvis-timestamp' => (string) NOW, 'x-jarvis-nonce' => $nonce, 'x-jarvis-signature' => $sig2])[0])->toBe(401);
    expect(call($h, 'GET', P.'/ping', null, ['ts' => NOW - 301])[1]['error'])->toBe('stale_or_malformed');
    expect(call($h, 'GET', P.'/ping', null, ['ts' => NOW + 301])[0])->toBe(401);
    expect($h->handle('GET', P.'/ping', '', [])[0])->toBe(401);
    expect(call($h, 'GET', P.'/ping', null, ['nonce' => 'short'])[0])->toBe(401);
    // replay: the very same signed request twice
    $ok = $h->handle('GET', P.'/ping', '', ['x-jarvis-timestamp' => (string) NOW, 'x-jarvis-nonce' => $nonce, 'x-jarvis-signature' => $sig]);
    $again = $h->handle('GET', P.'/ping', '', ['x-jarvis-timestamp' => (string) NOW, 'x-jarvis-nonce' => $nonce, 'x-jarvis-signature' => $sig]);
    expect($ok[0])->toBe(200)->and($again[1]['error'])->toBe('replay');
});

it('does nothing without a long enough secret', function () {
    [$h, $s] = rig(null, '');
    expect($h->handle('GET', P.'/ping', '', [])[0])->toBe(503);
    [$h] = rig(null, 'short');
    expect(call($h, 'GET', P.'/ping', null, ['secret' => 'short'])[0])->toBe(503);
});

it('a forged request cannot burn nonces', function () {
    [$h] = rig();
    $nonce = 'nonce-for-forgery-test-0123456789';
    expect(call($h, 'GET', P.'/ping', null, ['nonce' => $nonce, 'sig' => str_repeat('0', 64)])[0])->toBe(401);
    expect(call($h, 'GET', P.'/ping', null, ['nonce' => $nonce])[0])->toBe(200);
});

it('creates, reads, updates and pages products', function () {
    [$h, $s] = rig();
    [$st, $j] = call($h, 'POST', P.'/products', PRODUCT);
    expect($st)->toBe(201)->and($j['external_id'])->toBe('1')->and($s->products['1']['title'])->toBe('Tee')->and($s->products['1']['sale_price_minor'])->toBe(40000);
    expect(call($h, 'GET', P.'/products/1')[1]['title'])->toBe('Tee');
    [$st2, $j2] = call($h, 'PUT', P.'/products/1', ['title' => 'Tee 2'] + PRODUCT);
    expect($st2)->toBe(200)->and($s->products['1']['title'])->toBe('Tee 2');
    foreach (range(1, 4) as $i) {
        call($h, 'POST', P.'/products', PRODUCT);
    }
    $page = call($h, 'GET', P.'/products?limit=2')[1];
    expect($page['items'])->toHaveCount(2)->and($page['next'])->toBe('2');
    expect(call($h, 'GET', P.'/products?limit=2&cursor=2')[1]['items'][0]['external_id'])->toBe('3');
});

it('answers 404 for unknown products, variants and routes', function () {
    [$h] = rig();
    expect(call($h, 'GET', P.'/products/999')[0])->toBe(404)->and(call($h, 'PUT', P.'/products/999', PRODUCT)[0])->toBe(404)
        ->and(call($h, 'GET', P.'/nothing')[0])->toBe(404)->and(call($h, 'DELETE', P.'/products/1')[0])->toBe(404)
        ->and(call($h, 'POST', P.'/stock', ['variant_external_id' => 'zz/1', 'qty' => 1])[0])->toBe(404);
});

it('validates what JARVIS sends instead of guessing', function () {
    [$h, $s] = rig();
    $bad = [
        ['title' => ''] + PRODUCT, ['price_minor' => -1] + PRODUCT, ['price_minor' => '12.5'] + PRODUCT, ['sale_price_minor' => 50000] + PRODUCT,
        ['images' => ['javascript:alert(1)']] + PRODUCT, ['variants' => [['options' => ['a' => 1, 'b' => 2, 'c' => 3, 'd' => 4]]]] + PRODUCT, ['variants' => 'x'] + PRODUCT,
    ];
    foreach ($bad as $b) {
        expect(call($h, 'POST', P.'/products', $b)[0])->toBe(422);
    }
    expect($s->products)->toBe([]);
    foreach ([['qty' => 1], ['variant_external_id' => 'a', 'qty' => -1], ['variant_external_id' => 'a', 'qty' => '5'], ['variant_external_id' => '', 'qty' => 1]] as $b) {
        expect(call($h, 'POST', P.'/stock', $b)[0])->toBe(422);
    }
    expect(call($h, 'POST', P.'/products', null, ['raw' => '{not json'])[0])->toBe(401);   // body changed after signing
});

it('sets stock and reports bad json distinctly', function () {
    [$h, $s] = rig();
    expect(call($h, 'POST', P.'/stock', ['variant_external_id' => '5/9', 'qty' => 12])[0])->toBe(200)->and($s->stock)->toBe(['5/9' => 12]);
    $raw = '{oops';
    $nonce = 'jsonbad-nonce-0123456789abcdef';
    $sig = Signature::sign(SECRET, (string) NOW, $nonce, 'POST', P.'/stock', $raw);
    expect($h->handle('POST', P.'/stock', $raw, ['x-jarvis-timestamp' => (string) NOW, 'x-jarvis-nonce' => $nonce, 'x-jarvis-signature' => $sig])[1]['error'])->toBe('bad_json');
});

it('never leaks store errors', function () {
    [$h, $s] = rig();
    $s->fail = new \RuntimeException('SQLSTATE secret table name');
    [$st, $j] = call($h, 'GET', P.'/products');
    expect($st)->toBe(500)->and($j)->toBe(['error' => 'store_error']);
});

it('refuses oversized bodies', function () {
    [$h] = rig();
    expect($h->handle('POST', P.'/products', str_repeat('a', Handler::MAX_BODY + 1), [])[0])->toBe(413);
});

it('signature details: case-insensitive hex, method-bound, sub-folder shops sign the full path', function () {
    $s = Signature::sign(SECRET, '1', 'n', 'get', '/a?b=1', '');
    expect(Signature::valid(SECRET, strtoupper($s), '1', 'n', 'GET', '/a?b=1', ''))->toBeTrue()
        ->and(Signature::valid(SECRET, $s, '1', 'n', 'POST', '/a?b=1', ''))->toBeFalse()
        ->and(Signature::valid('', $s, '1', 'n', 'GET', '/a?b=1', ''))->toBeFalse();
    [$h] = rig();
    expect(call($h, 'GET', '/shop/jarvis-connector/v1/ping')[0])->toBe(200);
});
