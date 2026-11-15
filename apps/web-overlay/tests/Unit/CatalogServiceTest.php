<?php

use App\Catalog\CatalogService;
use Tests\Support\FakeCatalogRepo;

function svc(): array
{
    $r = new FakeCatalogRepo;

    return [new CatalogService($r), $r];
}

it('creates a product with its default variant and parses prices', function () {
    [$s, $r] = svc();
    $res = $s->create('T', 'U', ['title' => ' Tee ', 'price' => '1,250', 'sale_price' => '999.50', 'tags' => 'a, b|a', 'images' => "https://x.test/a.jpg\n\nhttps://x.test/b.jpg"]);
    expect($res)->toHaveKey('id');
    $p = $r->products[$res['id']];
    expect($p['title'])->toBe('Tee')->and($p['price_minor'])->toBe(125000)->and($p['sale_price_minor'])->toBe(99950)->and($p['tags'])->toBe(['a', 'b'])->and($p['images'])->toHaveCount(2);
    expect($r->variants)->toHaveCount(1)->and(array_values($r->variants)[0]['options'])->toBe([]);
});

it('returns field errors and creates nothing', function () {
    [$s, $r] = svc();
    $res = $s->create('T', 'U', ['title' => '', 'price' => 'abc', 'sale_price' => '5', 'images' => 'ftp://x', 'currency' => 'taka']);
    expect(array_keys($res['errors']))->toBe(['title', 'price', 'sale_price', 'currency', 'images'])->and($r->products)->toBe([]);
});

it('refuses a duplicate sku on create and on update', function () {
    [$s, $r] = svc();
    $a = $s->create('T', 'U', ['title' => 'A', 'price' => '10', 'sku' => 'X']);
    expect($s->create('T', 'U', ['title' => 'B', 'price' => '10', 'sku' => 'x'])['errors'])->toHaveKey('sku');
    $b = $s->create('T', 'U', ['title' => 'B', 'price' => '10', 'sku' => 'Y']);
    expect($s->update($b['id'], ['title' => 'B', 'price' => '10', 'sku' => 'X'])['errors'])->toHaveKey('sku');
    expect($s->update($a['id'], ['title' => 'A2', 'price' => '10', 'sku' => 'X']))->toBe(['ok' => true]);
});

it('does not edit an archived product', function () {
    [$s, $r] = svc();
    $a = $s->create('T', 'U', ['title' => 'A', 'price' => '10']);
    $r->products[$a['id']]['status'] = 'archived';
    expect($s->update($a['id'], ['title' => 'Z', 'price' => '10'])['errors'])->toHaveKey('title');
});

it('imports products and variants, sets stock through the ledger and reports a summary', function () {
    [$s, $r] = svc();
    $csv = "sku,title,price,stock,options,parent_sku\nT1,Tee,500,,,\nT1-M,,,5,Size=M,T1\nT1-L,,,2,Size=L,T1\nM1,Mug,300,10,,\nBAD,,1,,,\n";
    $sum = $s->import('T', 'U', 'a.csv', $csv);
    expect($sum['created'])->toBe(2)->and($sum['updated'])->toBe(0)->and($sum['failed'])->toBe(1)->and($sum['total'])->toBe(5);
    $qty = array_map(fn ($v) => $v['stock_qty'], array_values($r->variants));
    expect($qty)->toBe([5, 2, 10])->and(count($r->variants))->toBe(3)->and($r->imports)->toHaveCount(1);
    expect($r->moves[0]['reason'])->toBe('import');
});

it('re-importing updates by sku and moves stock to the new exact quantity', function () {
    [$s, $r] = svc();
    $s->import('T', 'U', 'a.csv', "sku,title,price,stock\nM1,Mug,300,10\n");
    $sum = $s->import('T', 'U', 'b.csv', "sku,title,price,stock\nM1,Mug v2,350,4\n");
    expect($sum['created'])->toBe(0)->and($sum['updated'])->toBe(1);
    $v = array_values($r->variants);
    expect($v)->toHaveCount(1)->and($v[0]['stock_qty'])->toBe(4);
    expect(array_values($r->products)[0]['title'])->toBe('Mug v2')->and(array_values($r->products)[0]['price_minor'])->toBe(35000);
});

it('keeps going when one product fails to save', function () {
    [$s, $r] = svc();
    $bad = new class extends FakeCatalogRepo
    {
        public function create(string $tenantId, ?string $userId, string $storeId, array $f): string
        {
            if ($f['title'] === 'Boom') {
                throw new \RuntimeException('products_sku_per_store');
            }

            return parent::create($tenantId, $userId, $storeId, $f);
        }
    };
    $s = new CatalogService($bad);
    $sum = $s->import('T', 'U', 'a.csv', "sku,title,price\nA,Boom,5\nB,Ok,5\n");
    expect($sum['created'])->toBe(1)->and($sum['failed'])->toBe(1)->and($sum['errors'][0]['message'])->toContain('SKU');
});

it('adjusts stock with checks and Bengali errors', function () {
    [$s, $r] = svc();
    $a = $s->create('T', 'U', ['title' => 'A', 'price' => '10']);
    $v = array_key_first($r->variants);
    expect($s->adjustStock($v, 5, 'adjust', null, 'U'))->toBe(['ok' => true, 'qty' => 5]);
    expect($s->adjustStock($v, -6, 'sale', null, 'U')['ok'])->toBeFalse();
    expect($s->adjustStock($v, 0, 'adjust', null, 'U')['ok'])->toBeFalse();
    expect($s->adjustStock($v, 1, 'initial', null, 'U')['ok'])->toBeFalse();   // 'initial'/'import'/'sync' are system reasons
    $r->variants[$v]['track_stock'] = false;
    expect($s->adjustStock($v, 1, 'adjust', null, 'U')['error'])->toContain('ট্র্যাক');
});

it('has a message for every workflow result', function () {
    foreach (['submitted', 'approved', 'rejected', 'published', 'archived', 'restored', 'emergency', 'not_draft', 'not_pending', 'not_approved', 'incomplete:description', 'incomplete:price', 'incomplete:variant'] as $c) {
        expect(CatalogService::message($c) === CatalogService::message('zzz'))->toBeFalse();
    }
});
