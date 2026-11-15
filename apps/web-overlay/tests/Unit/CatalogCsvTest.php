<?php

use App\Catalog\CatalogCsv;

it('parses products, prices in taka, tags, images and stock', function () {
    $r = CatalogCsv::parse("sku,title,description,price,sale_price,tags,images,stock,category\nT1,Tee,Soft,\"1,250.50\",1000,a|b,https://x.test/a.jpg|https://x.test/b.jpg,7,Shirts\n");
    expect($r['errors'])->toBe([])->and($r['products'])->toHaveCount(1);
    $p = $r['products'][0];
    expect($p['price_minor'])->toBe(125050)->and($p['sale_price_minor'])->toBe(100000)->and($p['tags'])->toBe(['a', 'b'])
        ->and($p['images'])->toHaveCount(2)->and($p['stock'])->toBe(7)->and($p['currency'])->toBe('BDT')->and($p['category'])->toBe('Shirts');
});

it('accepts a BOM, semicolons and Bangla digits', function () {
    $r = CatalogCsv::parse("\xEF\xBB\xBFsku;title;price\nA1;শার্ট;৳ ১২০০\n");
    expect($r['errors'])->toBe([])->and($r['products'][0]['price_minor'])->toBe(120000)->and($r['products'][0]['title'])->toBe('শার্ট');
});

it('groups variant rows under their parent and sorts options', function () {
    $r = CatalogCsv::parse("sku,title,price,stock,options,parent_sku\nT1,Tee,500,,,\nT1-M,,,5,Size=M;Color=Red,T1\nT1-L,,550,2,Size=L,T1\n");
    expect($r['errors'])->toBe([]);
    $v = $r['products'][0]['variants'];
    expect($v)->toHaveCount(2)->and($v[0]['options'])->toBe(['Color' => 'Red', 'Size' => 'M'])->and($v[1]['price_minor'])->toBe(55000)->and($v[1]['sku'])->toBe('T1-L');
});

it('reports bad rows by line number and keeps the good ones', function () {
    $r = CatalogCsv::parse("sku,title,price,stock\nA,Good,100,1\nB,,100,1\nC,BadPrice,abc,1\nD,BadStock,100,x\nE,Fine,200,\n");
    expect(array_column($r['products'], 'sku'))->toBe(['A', 'E'])
        ->and(array_column($r['errors'], 'row'))->toBe([3, 4, 5]);
});

it('rejects a sale price not below the price, bad currency, bad image url and duplicate sku', function () {
    $r = CatalogCsv::parse("sku,title,price,sale_price,currency,images\nA,T,100,100,BDT,\nB,T,100,,BDTX,\nC,T,100,,BDT,javascript:alert(1)\nD,T,100,,,\nD,T2,100,,,\n");
    expect(array_column($r['products'], 'sku'))->toBe(['D'])->and($r['errors'])->toHaveCount(4);
});

it('flags an orphan variant, duplicate options and malformed options', function () {
    $r = CatalogCsv::parse("sku,title,price,options,parent_sku\nT1,Tee,5,,\nX-1,,5,Size=M,GHOST\nT1-a,,5,Size=M,T1\nT1-b,,5,Size=M,T1\nT1-c,,5,oops,T1\n");
    expect($r['products'])->toBe([])->and(count($r['errors']))->toBe(3);
});

it('enforces size and row limits and needs a title column', function () {
    expect(CatalogCsv::parse(str_repeat('a', CatalogCsv::MAX_BYTES + 1))['errors'][0]['row'])->toBe(0);
    expect(CatalogCsv::parse("sku,price\nA,5\n")['errors'][0]['message'])->toContain('title');
    $rows = "title\n".implode("\n", array_map(fn ($i) => "P$i", range(1, CatalogCsv::MAX_ROWS + 5)));
    $r = CatalogCsv::parse($rows);
    expect(count($r['products']))->toBe(CatalogCsv::MAX_ROWS);
    expect(CatalogCsv::parse('')['errors'][0]['message'])->toBe('The file is empty.');
});
