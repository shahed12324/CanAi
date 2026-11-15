<?php

use App\Catalog\Stores\StoreException;
use App\Catalog\Stores\StoreFactory;
use App\Catalog\Stores\StoreSync;
use Tests\Support\FakeCatalogRepo;
use Tests\Support\FakeStoreAdapter;
use Tests\Support\FakeStoreRepo;

function sync(?FakeStoreAdapter $a = null): array
{
    $cat = new FakeCatalogRepo;
    $stores = new FakeStoreRepo($cat);
    $a ??= new FakeStoreAdapter;
    $factory = new class($a) extends StoreFactory
    {
        public function __construct(private FakeStoreAdapter $a) {}

        public function forStore(array $store): \App\Catalog\Stores\StoreAdapter
        {
            return $this->a;
        }
    };

    return [new StoreSync($cat, $stores, $factory), $cat, $stores, $a];
}

function go(StoreSync $s): array
{
    return $s->run('T', 'S1', 'manual', 'U');
}

function publishLocally(FakeCatalogRepo $cat, string $pid, array $live = []): void
{
    $p = $cat->products[$pid];
    $cat->products[$pid]['status'] = 'published';
    $cat->products[$pid]['live'] = $live + ['title' => $p['title'], 'description' => $p['description'], 'price_minor' => $p['price_minor'], 'sale_price_minor' => null, 'currency' => 'BDT', 'sku' => null, 'category' => null, 'tags' => [], 'images' => [],
        'variants' => array_values(array_map(fn ($id, $v) => ['id' => $id, 'sku' => $v['sku'], 'options' => $v['options'], 'price_minor' => $v['price_minor']], array_keys(array_filter($cat->variants, fn ($v) => $v['product_id'] === $pid)), array_filter($cat->variants, fn ($v) => $v['product_id'] === $pid)))];
}

it('pulls the store as DRAFT products with variants, and the first stock goes through the ledger', function () {
    [$s, $cat, $st, $a] = sync();
    $a->remote['W1'] = FakeStoreAdapter::item('W1', 'Tee', [
        ['external_id' => 'W1/10', 'sku' => 'T-M', 'options' => ['Size' => 'M'], 'price_minor' => null, 'stock' => 7],
        ['external_id' => 'W1/11', 'sku' => 'T-L', 'options' => ['Size' => 'L'], 'price_minor' => 55000, 'stock' => null],
    ]);
    $a->remote['W2'] = FakeStoreAdapter::item('W2', 'Mug');
    $r = go($s);
    expect($r['ok'])->toBeTrue()->and($r['pulled'])->toBe(2)->and($r['created'])->toBe(2)->and($r['status'])->toBe('ok');
    $p = array_values($cat->products);
    expect($p[0]['status'])->toBe('draft')->and($p[0]['external_id'])->toBe('W1')->and($p[1]['title'])->toBe('Mug');
    $v = array_values($cat->variants);
    expect(count($v))->toBe(3)->and($v[0]['stock_qty'])->toBe(7)->and($v[0]['remote_stock'])->toBe(7)->and($v[1]['track_stock'])->toBeFalse()->and($v[2]['stock_qty'])->toBe(5);
    expect($cat->moves[count($cat->moves) - 1]['reason'])->toBe('sync')->and($st->store['last_error'])->toBeNull();
});

it('a second pull with nothing new changes nothing', function () {
    [$s, $cat, $st, $a] = sync();
    $a->remote['W1'] = FakeStoreAdapter::item('W1');
    go($s);
    $moves = count($cat->moves);
    $r = go($s);
    expect($r['created'])->toBe(0)->and($r['updated'])->toBe(0)->and(count($cat->moves))->toBe($moves)->and(count($cat->products))->toBe(1);
});

it('a changed product in the store updates our copy; unchanged ones are left alone', function () {
    [$s, $cat, $st, $a] = sync();
    $a->remote['W1'] = FakeStoreAdapter::item('W1', 'Tee');
    $a->remote['W2'] = FakeStoreAdapter::item('W2', 'Mug');
    go($s);
    $a->remote['W1']['title'] = 'Tee v2';
    $r = go($s);
    expect($r['updated'])->toBe(1);
    $titles = array_column(array_values($cat->products), 'title');
    expect($titles)->toBe(['Tee v2', 'Mug']);
});

it('links an existing local product by SKU instead of duplicating it', function () {
    [$s, $cat, $st, $a] = sync();
    $local = $cat->create('T', 'U', 'S1', ['title' => 'Local tee', 'sku' => 'TEE-1', 'description' => 'x', 'price_minor' => 100]);
    $cat->addVariant('T', $local, null, [], null, true);
    $a->remote['W1'] = FakeStoreAdapter::item('W1', 'Remote tee', [], ['sku' => 'TEE-1']);
    $r = go($s);
    expect(count($cat->products))->toBe(1)->and($r['created'])->toBe(0)->and($r['updated'])->toBe(1)->and($cat->products[$local]['external_id'])->toBe('W1');
});

it('replaces the placeholder variant when the store has real variants', function () {
    [$s, $cat, $st, $a] = sync();
    $local = $cat->create('T', 'U', 'S1', ['title' => 'Local', 'sku' => 'X', 'description' => 'x', 'price_minor' => 100]);
    $ph = $cat->addVariant('T', $local, null, [], null, true);
    $a->remote['W1'] = FakeStoreAdapter::item('W1', 'R', [['external_id' => 'W1/1', 'sku' => null, 'options' => ['Size' => 'M'], 'price_minor' => null, 'stock' => 1]], ['sku' => 'X']);
    go($s);
    expect($cat->variants[$ph]['archived'])->toBeTrue();
});

it('merges stock three ways: only they changed, only we changed, both changed', function () {
    [$s, $cat, $st, $a] = sync();
    $a->remote['W1'] = FakeStoreAdapter::item('W1');
    go($s);
    $v = array_key_first($cat->variants);
    // only the store changed -> we follow
    $a->remote['W1']['variants'][0]['stock'] = 9;
    go($s);
    expect($cat->variants[$v]['stock_qty'])->toBe(9)->and($cat->variants[$v]['remote_stock'])->toBe(9);
    // only we changed (a sale) -> the store is told, we are not overwritten
    $cat->variants[$v]['stock_qty'] = 6;
    $cat->products[array_key_first($cat->products)]['live'] = ['x' => 1];
    $a->stockPushes = [];
    go($s);
    expect($cat->variants[$v]['stock_qty'])->toBe(6)->and($a->stockPushes)->toBe([['W1', 6]])->and($cat->variants[$v]['remote_stock'])->toBe(6);
    // both changed -> the store's number wins
    $cat->variants[$v]['stock_qty'] = 2;
    $a->remote['W1']['variants'][0]['stock'] = 20;
    go($s);
    expect($cat->variants[$v]['stock_qty'])->toBe(20);
});

it('pushes only the APPROVED copy of a published product and remembers the store id', function () {
    [$s, $cat, $st, $a] = sync();
    $pid = $cat->create('T', 'U', 'S1', ['title' => 'Draft title', 'description' => 'unapproved text', 'price_minor' => 70000, 'sku' => null]);
    $vid = $cat->addVariant('T', $pid, null, [], null, true);
    $cat->variants[$vid]['stock_qty'] = 4;
    publishLocally($cat, $pid, ['title' => 'Approved title', 'description' => 'approved text', 'price_minor' => 60000]);
    $cat->products[$pid]['title'] = 'Edited later (draft)';
    $r = go($s);
    expect($r['pushed'])->toBe(1)->and($a->pushes[0][0]['title'])->toBe('Approved title')->and($a->pushes[0][0]['description'])->toBe('approved text')->and($a->pushes[0][0]['price_minor'])->toBe(60000)
        ->and($a->pushes[0][0]['variants'][0]['stock'])->toBe(4)->and($cat->products[$pid]['external_id'])->toBe('R1');
    expect($cat->variants[$vid]['external_id'])->toBe('R1-1');
    // pulling right after our own push must not touch the product (hash of what the store holds)
    $r2 = go($s);
    expect($r2['updated'])->toBe(0)->and($r2['pushed'])->toBe(0)->and($cat->products[$pid]['title'])->toBe('Edited later (draft)');
});

it('does not push during an emergency stop but still pulls', function () {
    [$s, $cat, $st, $a] = sync();
    $st->emergency = true;
    $pid = $cat->create('T', 'U', 'S1', ['title' => 'P', 'description' => 'd', 'price_minor' => 1]);
    $cat->addVariant('T', $pid, null, [], null, true);
    publishLocally($cat, $pid);
    $a->remote['W9'] = FakeStoreAdapter::item('W9', 'Incoming');
    $r = go($s);
    expect($a->pushes)->toBe([])->and($r['pushed'])->toBe(0)->and($r['created'])->toBe(1)->and($r['note'])->toContain('emergency');
});

it('a rejected key stops the run, marks the store and says it in plain words', function () {
    [$s, $cat, $st, $a] = sync();
    $a->failPage = new StoreException('auth_failed');
    $r = go($s);
    expect($r['ok'])->toBeFalse()->and($r['status'])->toBe('failed')->and($st->store['status'])->toBe('error')->and($st->store['last_error'])->toContain('New Taxে Connections');
});

it('one bad product does not stop the others', function () {
    [$s, $cat, $st, $a] = sync();
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
    $stores = new FakeStoreRepo($bad);
    $a->remote['A'] = FakeStoreAdapter::item('A', 'Boom');
    $a->remote['B'] = FakeStoreAdapter::item('B', 'Fine');
    $factory = new class($a) extends StoreFactory
    {
        public function __construct(private FakeStoreAdapter $a) {}

        public function forStore(array $store): \App\Catalog\Stores\StoreAdapter
        {
            return $this->a;
        }
    };
    $r = (new StoreSync($bad, $stores, $factory))->run('T', 'S1', 'manual', 'U');
    expect($r['status'])->toBe('partial')->and($r['created'])->toBe(1)->and($r['failed'])->toBe(1)->and($r['errors'][0]['message'])->toContain('SKU');
});

it('refuses a second run, a paused store, another workspace and the native store', function () {
    [$s, $cat, $st, $a] = sync();
    $st->running = true;
    expect(go($s)['note'])->toBe('already_running');
    $st->running = false;
    $st->store['status'] = 'paused';
    expect(go($s)['note'])->toBe('paused');
    $st->store['status'] = 'active';
    expect($s->run('OTHER', 'S1', 'manual', 'U')['note'])->toBe('store_not_found');
    $st->store['platform'] = 'native';
    expect(go($s)['note'])->toBe('store_not_found');
});

it('pages through large stores', function () {
    [$s, $cat, $st, $a] = sync();
    $a->pageSize = 2;
    foreach (range(1, 5) as $i) {
        $a->remote["W$i"] = FakeStoreAdapter::item("W$i", "P$i");
    }
    expect(go($s)['created'])->toBe(5);
});

it('hashes content but not stock, and is order-stable', function () {
    $a = FakeStoreAdapter::item('W1');
    $b = $a;
    $b['variants'][0]['stock'] = 99;
    expect(StoreSync::hash($a))->toBe(StoreSync::hash($b));
    $b['title'] = 'x';
    expect(StoreSync::hash($a) === StoreSync::hash($b))->toBeFalse();
});
