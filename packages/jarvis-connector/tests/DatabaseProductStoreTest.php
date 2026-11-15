<?php

use Jarvis\Connector\DatabaseProductStore;
use Jarvis\Connector\PdoDb;

function shopDb(): PdoDb
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->exec('CREATE TABLE products (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, details TEXT, price DECIMAL(12,2) NOT NULL, discount_price DECIMAL(12,2), sku TEXT, quantity INTEGER DEFAULT 0, images TEXT, status INTEGER NOT NULL, updated_at TEXT, deleted_at TEXT, hidden INTEGER DEFAULT 0)');
    $pdo->exec('CREATE TABLE product_variants (id INTEGER PRIMARY KEY AUTOINCREMENT, product_id INTEGER NOT NULL, sku TEXT, price DECIMAL(12,2), quantity INTEGER DEFAULT 0, options TEXT)');

    return new PdoDb($pdo);
}

function cfg(array $over = []): array
{
    return array_replace_recursive([
        'table' => 'products', 'id' => 'id', 'currency' => 'BDT', 'price_unit' => 'major',
        'columns' => ['title' => 'name', 'description' => 'details', 'price' => 'price', 'sale_price' => 'discount_price', 'sku' => 'sku', 'stock' => 'quantity', 'images' => 'images'],
        'images' => ['format' => 'json', 'base_url' => 'https://shop.test/uploads'], 'touch' => 'updated_at', 'soft_delete' => 'deleted_at', 'insert_defaults' => ['status' => 1],
    ], $over);
}

function variantCfg(): array
{
    return ['variants' => ['table' => 'product_variants', 'id' => 'id', 'product_id' => 'product_id', 'sku' => 'sku', 'price' => 'price', 'stock' => 'quantity', 'options' => 'options']];
}

function newProduct(array $over = []): array
{
    return $over + ['title' => 'Tee', 'description' => "Soft\nCotton", 'price_minor' => 125050, 'sale_price_minor' => 99900, 'sku' => 'T1', 'category' => null, 'tags' => [], 'images' => [],
        'variants' => [['sku' => null, 'options' => [], 'price_minor' => null, 'stock' => 7]]];
}

it('creates a product with the shop columns, defaults and money as decimals', function () {
    $db = shopDb();
    $s = new DatabaseProductStore($db, cfg(), fn () => 1_800_000_000);
    $id = $s->upsert(null, newProduct());
    $row = $db->select('SELECT * FROM products WHERE id = ?', [$id])[0];
    expect($id)->toBe('1')->and($row['name'])->toBe('Tee')->and((float) $row['price'])->toBe(1250.5)->and((float) $row['discount_price'])->toBe(999.0)->and($row['quantity'])->toBe(7)
        ->and($row['status'])->toBe(1)->and($row['updated_at'])->toBe('2027-01-15 08:00:00')->and($row['images'])->toBeNull();
});

it('reads a product back in the canonical shape (paisa, plain text, image urls)', function () {
    $db = shopDb();
    $db->execute("INSERT INTO products (name, details, price, discount_price, sku, quantity, images, status) VALUES ('Mug', '<p>Hot &amp; cold</p><p>Ceramic</p>', 300.5, 250, 'M1', 4, '[\"a.jpg\",\"https://cdn.test/b.jpg\",\"javascript:1\"]', 1)");
    $p = (new DatabaseProductStore($db, cfg()))->find('1');
    expect($p['external_id'])->toBe('1')->and($p['price_minor'])->toBe(30050)->and($p['sale_price_minor'])->toBe(25000)->and($p['description'])->toBe("Hot & cold\nCeramic")
        ->and($p['images'])->toBe(['https://shop.test/uploads/a.jpg', 'https://cdn.test/b.jpg'])->and($p['variants'][0]['stock'])->toBe(4)->and($p['variants'][0]['external_id'])->toBe('1')->and($p['currency'])->toBe('BDT');
});

it('a discount that is not below the price is ignored on read', function () {
    $db = shopDb();
    $db->execute("INSERT INTO products (name, price, discount_price, status) VALUES ('X', 100, 100, 1), ('Y', 100, 0, 1)");
    $s = new DatabaseProductStore($db, cfg());
    expect($s->find('1')['sale_price_minor'])->toBeNull()->and($s->find('2')['sale_price_minor'])->toBeNull();
});

it('updates only mapped columns and 404s on unknown ids', function () {
    $db = shopDb();
    $s = new DatabaseProductStore($db, cfg());
    $s->upsert(null, newProduct());
    $db->execute('UPDATE products SET hidden = 9 WHERE id = 1');
    $s->upsert('1', newProduct(['title' => 'Tee v2', 'price_minor' => 200000, 'sale_price_minor' => null]));
    $row = $db->select('SELECT * FROM products WHERE id = 1')[0];
    expect($row['name'])->toBe('Tee v2')->and((float) $row['price'])->toBe(2000.0)->and($row['discount_price'])->toBeNull()->and($row['hidden'])->toBe(9)->and($row['status'])->toBe(1);
    expect(fn () => $s->upsert('99', newProduct()))->toThrow(OutOfBoundsException::class);
});

it('pages by id with a next cursor and hides soft-deleted and filtered rows', function () {
    $db = shopDb();
    foreach (range(1, 5) as $i) {
        $db->execute('INSERT INTO products (name, price, status) VALUES (?, 10, ?)', ["P$i", $i === 4 ? 0 : 1]);
    }
    $db->execute("UPDATE products SET deleted_at = '2026-01-01' WHERE id = 2");
    $s = new DatabaseProductStore($db, cfg(['where' => 'status = 1']));
    $a = $s->page(null, 2);
    expect(array_column($a['items'], 'title'))->toBe(['P1', 'P3'])->and($a['next'])->toBe('3');
    $b = $s->page($a['next'], 2);
    expect(array_column($b['items'], 'title'))->toBe(['P5'])->and($b['next'])->toBeNull();
    expect($s->find('2'))->toBeNull()->and($s->find('4'))->toBeNull();
    expect(fn () => $s->upsert('2', newProduct()))->toThrow(OutOfBoundsException::class);
});

it('stores prices as whole paisa when asked and never as floats', function () {
    $db = shopDb();
    $s = new DatabaseProductStore($db, cfg(['price_unit' => 'minor']));
    $s->upsert(null, newProduct(['price_minor' => 125050, 'sale_price_minor' => 99900]));
    $row = $db->select('SELECT price, discount_price FROM products')[0];
    expect((int) $row['price'])->toBe(125050)->and((int) $row['discount_price'])->toBe(99900);
    expect($s->find('1')['price_minor'])->toBe(125050);
    $m = new DatabaseProductStore(shopDb(), cfg());
    $m->upsert(null, newProduct(['price_minor' => 1999, 'sale_price_minor' => 5]));
    expect($m->find('1')['price_minor'])->toBe(1999)->and($m->find('1')['sale_price_minor'])->toBe(5);
});

it('leaves images alone unless write is on, and writes json or csv when it is', function () {
    $db = shopDb();
    $db->execute("INSERT INTO products (name, price, images, status) VALUES ('X', 5, '[\"keep.jpg\"]', 1)");
    (new DatabaseProductStore($db, cfg()))->upsert('1', newProduct(['images' => ['https://x.test/new.jpg']]));
    expect($db->select('SELECT images FROM products')[0]['images'])->toBe('["keep.jpg"]');
    (new DatabaseProductStore($db, cfg(['images' => ['write' => true]])))->upsert('1', newProduct(['images' => ['https://x.test/new.jpg']]));
    expect($db->select('SELECT images FROM products')[0]['images'])->toBe('["https://x.test/new.jpg"]');
    (new DatabaseProductStore($db, cfg(['images' => ['write' => true, 'format' => 'csv']])))->upsert('1', newProduct(['images' => ['https://x.test/a.jpg', 'https://x.test/b.jpg']]));
    expect($db->select('SELECT images FROM products')[0]['images'])->toBe('https://x.test/a.jpg,https://x.test/b.jpg');
});

it('works with a variants table: reads variants, upserts by options, updates stock', function () {
    $db = shopDb();
    $s = new DatabaseProductStore($db, cfg(variantCfg()));
    $id = $s->upsert(null, newProduct(['variants' => [
        ['sku' => 'T-M', 'options' => ['Size' => 'M', 'Color' => 'Red'], 'price_minor' => null, 'stock' => 5], ['sku' => 'T-L', 'options' => ['Size' => 'L', 'Color' => 'Red'], 'price_minor' => 130000, 'stock' => 2]]]));
    $p = $s->find($id);
    expect($p['variants'])->toHaveCount(2)->and($p['variants'][0]['external_id'])->toBe('1/1')->and($p['variants'][0]['options'])->toBe(['Color' => 'Red', 'Size' => 'M'])
        ->and($p['variants'][1]['price_minor'])->toBe(130000)->and($p['variants'][0]['stock'])->toBe(5);
    // update: same options -> same row; new options -> new row; nothing is deleted
    $s->upsert($id, newProduct(['variants' => [['sku' => 'T-M', 'options' => ['Color' => 'Red', 'Size' => 'M'], 'price_minor' => null, 'stock' => 9], ['sku' => 'T-XL', 'options' => ['Size' => 'XL', 'Color' => 'Red'], 'price_minor' => null, 'stock' => 1]]]));
    $v = $s->find($id)['variants'];
    expect($v)->toHaveCount(3)->and($v[0]['stock'])->toBe(9)->and($v[2]['sku'])->toBe('T-XL');
    $s->setStock('1/2', 11);
    expect($s->find($id)['variants'][1]['stock'])->toBe(11);
    expect(fn () => $s->setStock('1/99', 1))->toThrow(OutOfBoundsException::class);
    expect(fn () => $s->setStock('2/1', 1))->toThrow(OutOfBoundsException::class);                 // right variant id, wrong product
});

it('sets product-level stock, and refuses when the shop has no stock column', function () {
    $db = shopDb();
    $s = new DatabaseProductStore($db, cfg());
    $s->upsert(null, newProduct());
    $s->setStock('1', 42);
    expect($db->select('SELECT quantity FROM products')[0]['quantity'])->toBe(42);
    expect(fn () => $s->setStock('7', 1))->toThrow(OutOfBoundsException::class);
    $n = new DatabaseProductStore($db, cfg(['columns' => ['stock' => null]]));
    expect(fn () => $n->setStock('1', 1))->toThrow(InvalidArgumentException::class);
});

it('rolls back the whole product when a variant fails', function () {
    $db = shopDb();
    $s = new DatabaseProductStore($db, cfg(variantCfg()));
    $db->execute('DROP TABLE product_variants');
    try {
        $s->upsert(null, newProduct(['variants' => [['sku' => 'A', 'options' => ['Size' => 'M'], 'price_minor' => null, 'stock' => 1]]]));
    } catch (Throwable) {
    }
    expect($db->select('SELECT COUNT(*) AS n FROM products')[0]['n'])->toBe(0);
});

it('rejects config that could inject SQL or is incomplete', function () {
    foreach ([cfg(['table' => 'products; DROP TABLE x']), cfg(['columns' => ['title' => 'name) --']]), cfg(['soft_delete' => 'a b']), cfg(['insert_defaults' => ['x=1,y' => 1]]), cfg(['columns' => ['price' => null]]), cfg(['price_unit' => 'cents'])] as $bad) {
        expect(fn () => new DatabaseProductStore(shopDb(), $bad))->toThrow(InvalidArgumentException::class);
    }
});

it('a hostile id is only ever a bound value', function () {
    $db = shopDb();
    $s = new DatabaseProductStore($db, cfg());
    $s->upsert(null, newProduct());
    expect($s->find("1' OR '1'='1"))->toBeNull();
    expect(fn () => $s->setStock("1; DROP TABLE products", 1))->toThrow(OutOfBoundsException::class);
    expect($db->select('SELECT COUNT(*) AS n FROM products')[0]['n'])->toBe(1);
});
