<?php

use App\Catalog\Stores\ConnectorAdapter;
use App\Catalog\Stores\ConnectorSignature;
use App\Catalog\Stores\StoreConnector;
use App\Catalog\Stores\StoreException;
use App\Catalog\Stores\StoreGuard;
use App\Outreach\HttpJson;
use Tests\Support\FakeHttpJson;

const CSECRET = 'abcdefabcdefabcdefabcdefabcdefabcdefabcdefabcdef';
const CNOW = 1_800_000_000;

function conn(HttpJson $h): ConnectorAdapter
{
    return new ConnectorAdapter($h, 'https://shop.example.com', CSECRET, 'BDT', fn () => CNOW);
}

/** Feeds our signed requests straight into the shop package's Handler (when the package is next to us in the repo). */
function bridge(): HttpJson
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->exec('CREATE TABLE products (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, details TEXT, price DECIMAL(12,2) NOT NULL, discount_price DECIMAL(12,2), sku TEXT, quantity INTEGER DEFAULT 0, images TEXT, status INTEGER NOT NULL)');
    $pdo->exec('CREATE TABLE product_variants (id INTEGER PRIMARY KEY AUTOINCREMENT, product_id INTEGER NOT NULL, sku TEXT, price DECIMAL(12,2), quantity INTEGER DEFAULT 0, options TEXT)');
    $store = new Jarvis\Connector\DatabaseProductStore(new Jarvis\Connector\PdoDb($pdo), [
        'table' => 'products', 'id' => 'id', 'currency' => 'BDT', 'price_unit' => 'major', 'insert_defaults' => ['status' => 1], 'images' => ['format' => 'json', 'base_url' => 'https://shop.test'],
        'columns' => ['title' => 'name', 'description' => 'details', 'price' => 'price', 'sale_price' => 'discount_price', 'sku' => 'sku', 'stock' => 'quantity', 'images' => 'images'],
        'variants' => ['table' => 'product_variants', 'id' => 'id', 'product_id' => 'product_id', 'sku' => 'sku', 'price' => 'price', 'stock' => 'quantity', 'options' => 'options'],
    ], fn () => CNOW);
    $handler = new Jarvis\Connector\Handler($store, CSECRET, new Jarvis\Connector\ArrayNonceStore, fn () => CNOW, 'Demo Shop', 'BDT');

    return new class($handler) implements HttpJson
    {
        public array $seen = [];

        public function __construct(private $handler) {}

        public function request(string $method, string $url, array $headers = [], ?array $form = null, ?string $body = null): array
        {
            $p = parse_url($url);
            $uri = $p['path'].(isset($p['query']) ? '?'.$p['query'] : '');
            $this->seen[] = $uri;
            [$st, $json] = $this->handler->handle($method, $uri, $body ?? '', array_change_key_case($headers, CASE_LOWER));

            return ['status' => $st, 'json' => json_decode(json_encode($json), true) ?? []];
        }
    };
}

$hasPkg = class_exists('Jarvis\Connector\Handler');

it('signs exactly the path, query and body it sends, with a fresh nonce each time', function () {
    $h = new FakeHttpJson;
    $h->queue[] = ['status' => 200, 'json' => ['ok' => true, 'name' => 'Shop']];
    $h->queue[] = ['status' => 200, 'json' => ['ok' => true]];
    $a = conn($h);
    expect($a->ping())->toBe('Shop');
    $a->ping();
    $c = $h->calls[0];
    expect($c['url'])->toBe('https://shop.example.com/jarvis-connector/v1/ping')->and($c['headers']['X-Jarvis-Timestamp'])->toBe((string) CNOW)
        ->and($c['headers']['X-Jarvis-Signature'])->toBe(ConnectorSignature::sign(CSECRET, (string) CNOW, $c['headers']['X-Jarvis-Nonce'], 'GET', '/jarvis-connector/v1/ping', ''))
        ->and($c['headers']['X-Jarvis-Nonce'] === $h->calls[1]['headers']['X-Jarvis-Nonce'])->toBeFalse();
});

it('signs the query string and the JSON body of a push', function () {
    $h = new FakeHttpJson;
    $h->queue[] = ['status' => 200, 'json' => ['items' => [], 'next' => null]];
    $h->queue[] = ['status' => 201, 'json' => ['external_id' => '9']];
    $a = conn($h);
    $a->page('c 1');
    expect($h->calls[0]['url'])->toBe('https://shop.example.com/jarvis-connector/v1/products?limit=50&cursor=c%201');
    $a->push(['title' => 'T', 'description' => '', 'price_minor' => 100, 'variants' => [['options' => ['Size' => 'M'], 'stock' => 2]]], null);
    $c = $h->calls[1];
    expect($c['method'])->toBe('POST')->and($c['headers']['X-Jarvis-Signature'])->toBe(ConnectorSignature::sign(CSECRET, (string) CNOW, $c['headers']['X-Jarvis-Nonce'], 'POST', '/jarvis-connector/v1/products', $c['body']));
});

it('cleans whatever the shop sends back and ignores junk rows', function () {
    $h = new FakeHttpJson;
    $h->queue[] = ['status' => 200, 'json' => ['items' => [
        ['external_id' => '1', 'title' => '  Tee ', 'price_minor' => 500, 'sale_price_minor' => 900, 'images' => ['javascript:1', 'https://x.test/a.jpg'], 'tags' => ['a', 5], 'variants' => [['external_id' => '1', 'stock' => -4]]],
        ['title' => 'no id'], 'garbage',
    ], 'next' => '2']];
    $r = conn($h)->page(null);
    expect($r['items'])->toHaveCount(1)->and($r['next'])->toBe('2');
    $i = $r['items'][0];
    expect($i['title'])->toBe('Tee')->and($i['sale_price_minor'])->toBeNull()->and($i['images'])->toBe(['https://x.test/a.jpg'])->and($i['tags'])->toBe(['a'])->and($i['variants'][0]['stock'])->toBe(0)->and($i['currency'])->toBe('BDT');
});

it('maps shop errors: bad signature -> auth_failed, missing product -> null', function () {
    $h = new FakeHttpJson;
    $h->queue[] = ['status' => 401, 'json' => []];
    expect(fn () => conn($h)->ping())->toThrow(StoreException::class);
    $h->queue[] = ['status' => 404, 'json' => []];
    expect(conn($h)->fetch('77'))->toBeNull();
});

it('is accepted by the guard like a WooCommerce address and needs a 32+ char secret', function () {
    expect(StoreGuard::normalise('jarvis_connector', 'https://shop.example.com/', fn ($h) => ['93.184.216.34']))->toBe('https://shop.example.com');
    expect(fn () => StoreGuard::normalise('jarvis_connector', 'http://shop.example.com', fn ($h) => ['93.184.216.34']))->toThrow(StoreException::class);
});

if ($hasPkg) {
    it('CROSS: a real adapter talks to the real shop handler end to end (create, read, update, stock, paging)', function () {
        $b = bridge();
        $a = conn($b);
        expect($a->ping())->toBe('Demo Shop');
        $id = $a->push(['title' => 'Tee', 'description' => 'Soft', 'price_minor' => 50000, 'sale_price_minor' => 40000, 'sku' => 'T1', 'tags' => [], 'images' => [],
            'variants' => [['sku' => null, 'options' => ['Size' => 'M'], 'price_minor' => null, 'stock' => 3]]], null);
        $got = $a->fetch($id);
        expect($got['title'])->toBe('Tee')->and($got['price_minor'])->toBe(50000)->and($got['sale_price_minor'])->toBe(40000)->and($got['variants'][0]['stock'])->toBe(3);
        $a->push(['title' => 'Tee 2', 'description' => 'Soft', 'price_minor' => 60000, 'tags' => [], 'images' => [], 'variants' => [['options' => ['Size' => 'M'], 'stock' => 3]]], $id);
        $a->pushStock($got['variants'][0]['external_id'], 11);
        $again = $a->fetch($id);
        expect($again['title'])->toBe('Tee 2')->and($again['variants'][0]['stock'])->toBe(11)->and($a->page(null)['items'])->toHaveCount(1)->and($a->fetch('nope'))->toBeNull();
    });

    it('CROSS: a wrong secret is refused by the shop, and a replayed request is refused', function () {
        $b = bridge();
        $bad = new ConnectorAdapter($b, 'https://shop.example.com', 'z'.CSECRET, 'BDT', fn () => CNOW);
        try {
            $bad->ping();
            throw new AssertionError('should fail');
        } catch (StoreException $e) {
            expect($e->errorCode)->toBe('auth_failed');
        }
        $old = new ConnectorAdapter($b, 'https://shop.example.com', CSECRET, 'BDT', fn () => CNOW - 1000);
        expect(fn () => $old->ping())->toThrow(StoreException::class);
    });
}
