<?php

use App\Catalog\Stores\ShopifyAdapter;
use App\Catalog\Stores\StoreConnector;
use App\Catalog\Stores\StoreException;
use App\Catalog\Stores\StoreFactory;
use App\Catalog\Stores\StoreGuard;
use App\Catalog\Stores\WooAdapter;
use Tests\Support\FakeCatalogRepo;
use Tests\Support\FakeHttpJson;
use Tests\Support\FakeStoreRepo;

function woo(FakeHttpJson $h): WooAdapter
{
    return new WooAdapter($h, 'https://shop.example.com/', 'ck_1', 'cs_2', 'BDT');
}

function shopify(FakeHttpJson $h): ShopifyAdapter
{
    return new ShopifyAdapter($h, 'my-shop.myshopify.com', 'shpat_x', 'BDT', '2026-07');
}

function ok(array $json, int $status = 200): array
{
    return ['status' => $status, 'json' => $json];
}

it('Woo: sends Basic auth over https, maps a simple product and strips HTML', function () {
    $h = new FakeHttpJson;
    $h->queue[] = ok([['id' => 12, 'type' => 'simple', 'name' => 'Tee', 'description' => '<p>Soft &amp; warm</p><p>Cotton</p>', 'regular_price' => '1250.50', 'sale_price' => '999', 'sku' => 'T1',
        'manage_stock' => true, 'stock_quantity' => 7, 'categories' => [['name' => 'Shirts']], 'tags' => [['name' => 'cotton']], 'images' => [['src' => 'https://x.test/a.jpg'], ['src' => 'javascript:1']]]]);
    $r = woo($h)->page(null);
    expect($h->calls[0]['url'])->toBe('https://shop.example.com/wp-json/wc/v3/products?per_page=50&orderby=id&order=asc&page=1')
        ->and($h->calls[0]['headers']['Authorization'])->toBe('Basic '.base64_encode('ck_1:cs_2'))->and($r['next'])->toBeNull();
    $i = $r['items'][0];
    expect($i['external_id'])->toBe('12')->and($i['description'])->toBe("Soft & warm\nCotton")->and($i['price_minor'])->toBe(125050)->and($i['sale_price_minor'])->toBe(99900)
        ->and($i['images'])->toBe(['https://x.test/a.jpg'])->and($i['category'])->toBe('Shirts')->and($i['tags'])->toBe(['cotton'])->and($i['variants'][0]['stock'])->toBe(7)->and($i['variants'][0]['external_id'])->toBe('12');
});

it('Woo: reads variations of a variable product and pages by number', function () {
    $h = new FakeHttpJson;
    $rows = array_fill(0, 50, ['id' => 5, 'type' => 'simple', 'name' => 'X', 'regular_price' => '1']);
    $h->queue[] = ok($rows);
    expect(woo($h)->page('2')['next'])->toBe('3');
    $h = new FakeHttpJson;
    $h->queue[] = ok([['id' => 9, 'type' => 'variable', 'name' => 'Shirt', 'regular_price' => '', 'price' => '500']]);
    $h->queue[] = ok([['id' => 91, 'sku' => 'S-M', 'regular_price' => '500', 'manage_stock' => true, 'stock_quantity' => 3, 'attributes' => [['name' => 'Size', 'option' => 'M']]], ['id' => 92, 'regular_price' => '', 'manage_stock' => false, 'attributes' => [['name' => 'Size', 'option' => 'L']]]]);
    $i = woo($h)->page(null)['items'][0];
    expect($i['variants'][0]['external_id'])->toBe('9/91')->and($i['variants'][0]['options'])->toBe(['Size' => 'M'])->and($i['variants'][1]['stock'])->toBeNull()->and($i['price_minor'])->toBe(50000);
});

it('Woo: translates HTTP failures into plain error codes', function () {
    foreach ([[401, 'auth_failed'], [403, 'auth_failed'], [404, 'not_found'], [429, 'rate_limited'], [500, 'bad_response']] as [$s, $code]) {
        $h = new FakeHttpJson;
        $h->queue[] = ok([], $s);
        try {
            woo($h)->ping();
            $got = 'none';
        } catch (StoreException $e) {
            $got = $e->errorCode;
        }
        expect($got)->toBe($code);
    }
});

it('Woo: pushes a simple product, a variable product with variations, and stock', function () {
    $h = new FakeHttpJson;
    $h->queue[] = ok(['id' => 77]);
    $id = woo($h)->push(['title' => 'Mug', 'description' => "Line1\n<b>x</b>", 'price_minor' => 30000, 'sale_price_minor' => null, 'sku' => 'M1', 'images' => ['https://x.test/m.jpg'], 'variants' => [['sku' => null, 'options' => [], 'price_minor' => null, 'stock' => 5]]], null);
    $b = json_decode($h->calls[0]['body'], true);
    expect($id)->toBe('77')->and($h->calls[0]['method'])->toBe('POST')->and($b['type'])->toBe('simple')->and($b['regular_price'])->toBe('300.00')->and($b['stock_quantity'])->toBe(5)
        ->and($b['description'])->toBe("Line1<br>\n&lt;b&gt;x&lt;/b&gt;")->and($b['images'])->toBe([['src' => 'https://x.test/m.jpg']]);

    $h = new FakeHttpJson;
    $h->queue[] = ok(['id' => 80]);
    $h->queue[] = ok([]);
    woo($h)->push(['title' => 'Shirt', 'description' => 'd', 'price_minor' => 50000, 'sale_price_minor' => null, 'sku' => null, 'images' => [], 'variants' => [
        ['sku' => 'S-M', 'options' => ['Size' => 'M'], 'price_minor' => null, 'stock' => 2], ['sku' => 'S-L', 'options' => ['Size' => 'L'], 'price_minor' => 55000, 'stock' => null]]], null);
    $prod = json_decode($h->calls[0]['body'], true);
    $batch = json_decode($h->calls[1]['body'], true);
    expect($prod['type'])->toBe('variable')->and($prod['attributes'][0]['options'])->toBe(['M', 'L'])->and($h->calls[1]['url'])->toContain('/products/80/variations/batch')
        ->and(count($batch['create']))->toBe(2)->and($batch['create'][1]['regular_price'])->toBe('550.00')->and($batch['create'][0]['stock_quantity'])->toBe(2);

    $h = new FakeHttpJson;
    $h->queue[] = ok([]);
    $h->queue[] = ok([]);
    woo($h)->pushStock('80/91', 4);
    woo($h)->pushStock('77', 1);
    expect($h->calls[0]['url'])->toContain('products/80/variations/91')->and($h->calls[0]['method'])->toBe('PUT')->and($h->calls[1]['url'])->toEndWith('products/77');
});

it('Shopify: token header, since_id paging, default-title product, compare-at price as regular+sale', function () {
    $h = new FakeHttpJson;
    $h->queue[] = ok(['products' => [['id' => 1, 'title' => 'Tee', 'body_html' => '<p>Soft</p>', 'product_type' => 'Shirts', 'tags' => 'a, b', 'images' => [['src' => 'https://x.test/t.jpg']],
        'options' => [['name' => 'Title']], 'variants' => [['id' => 11, 'sku' => 'T1', 'price' => '900.00', 'compare_at_price' => '1200.00', 'option1' => 'Default Title', 'inventory_management' => 'shopify', 'inventory_quantity' => 4]]]]]);
    $r = shopify($h)->page('55');
    $i = $r['items'][0];
    expect($h->calls[0]['url'])->toBe('https://my-shop.myshopify.com/admin/api/2026-07/products.json?limit=100&since_id=55')->and($h->calls[0]['headers']['X-Shopify-Access-Token'])->toBe('shpat_x')
        ->and($i['price_minor'])->toBe(120000)->and($i['sale_price_minor'])->toBe(90000)->and($i['tags'])->toBe(['a', 'b'])->and($i['variants'][0]['options'])->toBe([])->and($i['variants'][0]['stock'])->toBe(4)
        ->and($i['sku'])->toBe('T1')->and($r['next'])->toBeNull();
});

it('Shopify: maps option names to variants, untracked inventory is null, next cursor is the last id', function () {
    $h = new FakeHttpJson;
    $rows = [];
    foreach (range(1, 100) as $n) {
        $rows[] = ['id' => $n, 'title' => 'P', 'options' => [['name' => 'Size'], ['name' => 'Color']], 'variants' => [['id' => $n * 10, 'price' => '5', 'option1' => 'M', 'option2' => 'Red', 'inventory_management' => null]]];
    }
    $h->queue[] = ok(['products' => $rows]);
    $r = shopify($h)->page(null);
    expect($r['next'])->toBe('100')->and($r['items'][0]['variants'][0]['options'])->toBe(['Size' => 'M', 'Color' => 'Red'])->and($r['items'][0]['variants'][0]['stock'])->toBeNull();
});

it('Shopify: pushes a product with options and sets stock through inventory levels', function () {
    $h = new FakeHttpJson;
    $h->queue[] = ok(['product' => ['id' => 500]]);
    $id = shopify($h)->push(['title' => 'Shirt', 'description' => 'd', 'price_minor' => 100000, 'sale_price_minor' => 80000, 'sku' => null, 'category' => 'Tops', 'tags' => ['x', 'y'], 'images' => [], 'variants' => [
        ['sku' => 'S-M', 'options' => ['Size' => 'M'], 'price_minor' => null, 'stock' => 3]]], null);
    $b = json_decode($h->calls[0]['body'], true)['product'];
    expect($id)->toBe('500')->and($b['options'])->toBe([['name' => 'Size']])->and($b['variants'][0]['price'])->toBe('800.00')->and($b['variants'][0]['compare_at_price'])->toBe('1000.00')
        ->and($b['variants'][0]['option1'])->toBe('M')->and($b['variants'][0]['inventory_quantity'])->toBe(3)->and($b['tags'])->toBe('x, y');

    $h = new FakeHttpJson;
    $h->queue[] = ok(['variant' => ['inventory_item_id' => 900]]);
    $h->queue[] = ok(['locations' => [['id' => 1, 'active' => false], ['id' => 2, 'active' => true]]]);
    $h->queue[] = ok([]);
    shopify($h)->pushStock('11', 6);
    expect($h->calls[2]['url'])->toContain('inventory_levels/set.json')->and(json_decode($h->calls[2]['body'], true))->toBe(['location_id' => 2, 'inventory_item_id' => 900, 'available' => 6]);
});

it('Shopify: only real *.myshopify.com hosts are ever called', function () {
    foreach (['evil.com', 'a.myshopify.com.evil.com', '10.0.0.1', 'my shop.myshopify.com', '-x.myshopify.com'] as $bad) {
        expect(ShopifyAdapter::validShop($bad))->toBeFalse();
        $h = new FakeHttpJson;
        $a = new ShopifyAdapter($h, $bad, 't');
        try {
            $a->ping();
        } catch (StoreException $e) {
        }
        expect($h->calls)->toBe([]);
    }
});

it('the guard accepts public https shops and refuses private, plain-http, credentialed and odd addresses', function () {
    $pub = fn () => ['93.184.216.34'];
    $priv = fn () => ['10.0.0.5'];
    expect(StoreGuard::normalise('woocommerce', 'Shop.Example.com/', $pub))->toBe('https://shop.example.com')
        ->and(StoreGuard::normalise('woocommerce', 'https://shop.example.com/store', $pub))->toBe('https://shop.example.com/store')
        ->and(StoreGuard::normalise('shopify', 'https://My-Shop.myshopify.com/admin', $pub))->toBe('my-shop.myshopify.com');
    foreach (['http://shop.example.com', 'https://u:p@shop.example.com', 'https://shop.example.com:8443', 'https://shop.example.com?x=1', 'https://localhost', 'https://127.0.0.1', 'https://shop.example.com/../etc'] as $bad) {
        try {
            StoreGuard::normalise('woocommerce', $bad, $pub);
            $got = 'accepted';
        } catch (StoreException $e) {
            $got = 'refused';
        }
        expect($got)->toBe('refused');
    }
    try {
        StoreGuard::normalise('woocommerce', 'https://shop.example.com', $priv);
        $got = 'accepted';
    } catch (StoreException $e) {
        $got = 'refused';
    }
    expect($got)->toBe('refused');
});

it('the connector proves the key first, then stores it encrypted, and never stores a bad one', function () {
    $repo = new FakeStoreRepo(new FakeCatalogRepo);
    $h = new FakeHttpJson;
    $factory = new class($h) extends StoreFactory
    {
        public function __construct(private FakeHttpJson $h) {}

        public function build(string $platform, string $baseUrl, array $creds, string $currency): \App\Catalog\Stores\StoreAdapter
        {
            return StoreFactory::buildWith($this->h, $platform, $baseUrl, $creds, $currency);
        }

        public function seal(array $creds): array
        {
            return ['cipher' => 'enc:'.strrev(base64_encode(json_encode($creds))), 'version' => 1];
        }
    };
    $con = new StoreConnector($repo, $factory, fn () => ['93.184.216.34']);

    $h->queue[] = ok([['id' => 1]]);
    $r = $con->connect('T', 'woocommerce', 'My shop', 'https://shop.example.com', ['key' => 'ck_a', 'secret' => 'cs_b'], 'bdt');
    expect($r['id'])->toBe('NEWSTORE')->and($repo->created[0]['currency'])->toBe('BDT');
    $stored = $repo->created[0];
    expect(str_contains($stored['cipher'], 'cs_b'))->toBeFalse()->and($stored['cipher'])->toStartWith('enc:');

    $h->queue[] = ok([], 401);
    $bad = $con->connect('T', 'woocommerce', 'x', 'https://shop.example.com', ['key' => 'ck_a', 'secret' => 'nope'], 'BDT');
    expect($bad['error'])->toContain('গ্রহণ Taxেনি')->and(count($repo->created))->toBe(1);
    expect($con->connect('T', 'woocommerce', '', 'https://shop.example.com', ['key' => 'a', 'secret' => 'b'], 'BDT')['error'])->toContain('Name');
    expect($con->connect('T', 'shopify', 'x', 'evil.com', ['token' => 't'], 'BDT')['error'])->toContain('myshopify');
    expect($con->connect('T', 'shopify', 'x', 'a.myshopify.com', ['token' => 'has space'], 'BDT')['error'])->toContain('টোকেন');
    expect(count($h->calls))->toBe(2);

    // JARVIS Connector: only a long secret is accepted, and it is proven with a signed ping before saving
    expect($con->connect('T', 'jarvis_connector', 'Own shop', 'https://shop.example.com', ['secret' => 'too-short'], 'BDT')['error'])->toContain('Key');
    expect(count($h->calls))->toBe(2);
    $h->queue[] = ok(['ok' => true, 'name' => 'Own shop']);
    $r = $con->connect('T', 'jarvis_connector', 'Own shop', 'https://shop.example.com', ['secret' => str_repeat('ab', 24)], 'BDT');
    expect($r['id'])->toBe('NEWSTORE')->and($h->calls[2]['url'])->toBe('https://shop.example.com/jarvis-connector/v1/ping')->and($h->calls[2]['headers'])->toHaveKey('X-Jarvis-Signature');
});
