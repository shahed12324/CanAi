<?php

use App\Couriers\CourierException;
use App\Couriers\CourierStatus;
use App\Couriers\PathaoAdapter;
use App\Couriers\RedxAdapter;
use App\Couriers\SteadfastAdapter;
use Tests\Support\FakeHttpJson;

function parcel(array $over = []): array
{
    return $over + ['invoice' => 'ORD-7', 'name' => 'রহিম', 'phone' => '01711223344', 'address' => 'House 12, Road 5, Dhanmondi, Dhaka', 'cod' => 1310, 'qty' => 2, 'note' => null, 'hints' => []];
}

function ok(array $json, int $status = 200): array
{
    return ['status' => $status, 'json' => $json];
}

function boom(callable $f): ?CourierException
{
    try {
        $f();
    } catch (CourierException $e) {
        return $e;
    }

    return null;
}

it('steadfast sends the keys as headers and the parcel as json, and reads the consignment', function () {
    $h = new FakeHttpJson;
    $h->queue[] = ok(['status' => 200, 'consignment' => ['consignment_id' => 1424107, 'tracking_code' => '15BAEB8A', 'status' => 'in_review']]);
    $r = (new SteadfastAdapter($h, ['api_key' => 'KEY', 'secret_key' => 'SEC']))->book(parcel());
    expect($r)->toBe(['consignment_id' => '1424107', 'tracking_code' => '15BAEB8A', 'raw_status' => 'in_review']);
    $c = $h->calls[0];
    expect($c['method'])->toBe('POST');
    expect($c['url'])->toBe('https://portal.packzy.com/api/v1/create_order');
    expect($c['headers']['Api-Key'])->toBe('KEY');
    expect($c['headers']['Secret-Key'])->toBe('SEC');
    $b = json_decode($c['body'], true);
    expect($b['invoice'])->toBe('ORD-7');
    expect($b['recipient_phone'])->toBe('01711223344');
    expect($b['cod_amount'])->toBe(1310);
});

it('steadfast status reads delivery_status from the consignment url', function () {
    $h = new FakeHttpJson;
    $h->queue[] = ok(['status' => 200, 'delivery_status' => 'delivered']);
    expect((new SteadfastAdapter($h, ['api_key' => 'K', 'secret_key' => 'S']))->status('1424107', null))->toBe('delivered');
    expect($h->calls[0]['url'])->toBe('https://portal.packzy.com/api/v1/status_by_cid/1424107');
    expect($h->calls[0]['method'])->toBe('GET');
});

it('a consignment id with odd characters never reaches the url', function () {
    $h = new FakeHttpJson;
    expect(boom(fn () => (new SteadfastAdapter($h, ['api_key' => 'K', 'secret_key' => 'S']))->status('1/../x', null)) !== null)->toBeTrue();
    expect($h->calls)->toBe([]);
});

it('4xx is a definite refusal, 5xx and network errors are uncertain, and secrets are scrubbed', function () {
    $h = new FakeHttpJson;
    $h->queue[] = ok(['message' => 'bad key KEY'], 401);
    $e = boom(fn () => (new SteadfastAdapter($h, ['api_key' => 'KEY', 'secret_key' => 'SEC']))->book(parcel()));
    expect($e->uncertain)->toBeFalse();
    expect(str_contains($e->getMessage(), 'KEY'))->toBeFalse();
    expect(str_contains($e->getMessage(), '401'))->toBeTrue();
    $h->queue[] = ok([], 503);
    expect(boom(fn () => (new SteadfastAdapter($h, ['api_key' => 'K', 'secret_key' => 'S']))->book(parcel()))->uncertain)->toBeTrue();
    $net = new class implements App\Outreach\HttpJson {
        public function request(string $method, string $url, array $headers = [], ?array $form = null, ?string $body = null): array
        {
            throw new RuntimeException('timeout');
        }
    };
    expect(boom(fn () => (new SteadfastAdapter($net, ['api_key' => 'K', 'secret_key' => 'S']))->book(parcel()))->uncertain)->toBeTrue();
});

it('an accepted call whose body does not say created is treated as uncertain, not as success', function () {
    $h = new FakeHttpJson;
    $h->queue[] = ok(['status' => 400, 'errors' => ['x']]);
    expect(boom(fn () => (new SteadfastAdapter($h, ['api_key' => 'K', 'secret_key' => 'S']))->book(parcel()))->uncertain)->toBeTrue();
});

it('missing keys fail before any request', function () {
    $h = new FakeHttpJson;
    expect(boom(fn () => (new SteadfastAdapter($h, ['api_key' => 'K']))->book(parcel())) !== null)->toBeTrue();
    expect($h->calls)->toBe([]);
});

it('pathao gets a token first, then books with it, using the store id and the sandbox host when asked', function () {
    $h = new FakeHttpJson;
    $h->queue[] = ok(['access_token' => 'TOK']);
    $h->queue[] = ok(['code' => 200, 'data' => ['consignment_id' => 'DL121224VS8TTJ', 'order_status' => 'Pending']]);
    $creds = ['client_id' => 'cid', 'client_secret' => 'csec', 'username' => 'a@b.c', 'password' => 'pw'];
    $r = (new PathaoAdapter($h, $creds, ['store_id' => '77', 'sandbox' => true]))->book(parcel());
    expect($r['consignment_id'])->toBe('DL121224VS8TTJ');
    expect($h->calls[0]['url'])->toBe('https://courier-api-sandbox.pathao.com/aladdin/api/v1/issue-token');
    expect(json_decode($h->calls[0]['body'], true)['grant_type'])->toBe('password');
    expect($h->calls[1]['url'])->toBe('https://courier-api-sandbox.pathao.com/aladdin/api/v1/orders');
    expect($h->calls[1]['headers']['Authorization'])->toBe('Bearer TOK');
    $b = json_decode($h->calls[1]['body'], true);
    expect($b['store_id'])->toBe(77);
    expect($b['amount_to_collect'])->toBe(1310);
    expect($b['merchant_order_id'])->toBe('ORD-7');
});

it('pathao: a failed token request is a definite refusal (nothing was created), and a missing store id stops early', function () {
    $h = new FakeHttpJson;
    $h->queue[] = ok([], 503);
    $creds = ['client_id' => 'cid', 'client_secret' => 'csec', 'username' => 'a@b.c', 'password' => 'pw'];
    expect(boom(fn () => (new PathaoAdapter($h, $creds, ['store_id' => '77']))->book(parcel()))->uncertain)->toBeFalse();
    $h2 = new FakeHttpJson;
    expect(boom(fn () => (new PathaoAdapter($h2, $creds, []))->book(parcel())) !== null)->toBeTrue();
    expect($h2->calls)->toBe([]);
});

it('pathao status reuses one token and reads order_status', function () {
    $h = new FakeHttpJson;
    $h->queue[] = ok(['access_token' => 'TOK']);
    $h->queue[] = ok(['data' => ['order_status' => 'Delivered']]);
    $a = new PathaoAdapter($h, ['client_id' => 'c', 'client_secret' => 's', 'username' => 'u', 'password' => 'p'], []);
    expect($a->status('DL1', null))->toBe('Delivered');
    expect($h->calls[1]['url'])->toBe('https://api-hermes.pathao.com/aladdin/api/v1/orders/DL1/info');
});

it('redx matches the delivery area from the address, or uses the id the person gave', function () {
    $areas = ['areas' => [['id' => 1, 'name' => 'Dhanmondi'], ['id' => 2, 'name' => 'Mirpur'], ['id' => 3, 'name' => 'Dhaka Cantonment']]];
    $h = new FakeHttpJson;
    $h->queue[] = ok($areas);
    $h->queue[] = ok(['tracking_id' => '20A316TU']);
    $r = (new RedxAdapter($h, ['access_token' => 'TK'], ['pickup_store_id' => '9']))->book(parcel());
    expect($r['consignment_id'])->toBe('20A316TU');
    expect($h->calls[1]['headers']['API-ACCESS-TOKEN'])->toBe('Bearer TK');
    $b = json_decode($h->calls[1]['body'], true);
    expect($b['delivery_area_id'])->toBe(1);
    expect($b['cash_collection_amount'])->toBe('1310');
    expect($b['pickup_store_id'])->toBe(9);
    $h2 = new FakeHttpJson;
    $h2->queue[] = ok($areas);
    $h2->queue[] = ok(['tracking_id' => 'X1']);
    (new RedxAdapter($h2, ['access_token' => 'TK']))->book(parcel(['address' => 'Somewhere unknown, Dhaka 1200', 'hints' => ['area_id' => '2']]));
    expect(json_decode($h2->calls[1]['body'], true)['delivery_area_id'])->toBe(2);
});

it('redx: no matching area is a clear refusal before booking, and a wrong area id too', function () {
    $areas = ['areas' => [['id' => 1, 'name' => 'Dhanmondi']]];
    $h = new FakeHttpJson;
    $h->queue[] = ok($areas);
    $e = boom(fn () => (new RedxAdapter($h, ['access_token' => 'T']))->book(parcel(['address' => 'Unknown place, 1200 Dhaka'])));
    expect($e !== null && ! $e->uncertain)->toBeTrue();
    expect(count($h->calls))->toBe(1);                                     // never reached /parcel
    $h2 = new FakeHttpJson;
    $h2->queue[] = ok($areas);
    expect(boom(fn () => (new RedxAdapter($h2, ['access_token' => 'T']))->book(parcel(['hints' => ['area_id' => '99']]))) !== null)->toBeTrue();
});

it('redx status takes the newest tracking row', function () {
    $h = new FakeHttpJson;
    $h->queue[] = ok(['tracking' => [['message_en' => 'Parcel picked up', 'time' => '2026-11-01T10:00:00'], ['message_en' => 'Delivered', 'time' => '2026-11-02T10:00:00'], ['message_en' => 'Out for delivery', 'time' => '2026-11-01T18:00:00']]]);
    expect((new RedxAdapter($h, ['access_token' => 'T']))->status('20A316TU', null))->toBe('Delivered');
});

it('status mapping: steadfast exact words', function () {
    foreach (['pending' => 'in_transit', 'in_review' => 'in_transit', 'delivered' => 'delivered', 'cancelled' => 'cancelled', 'partial_delivered' => 'attention', 'hold' => 'attention',
        'unknown' => 'attention', 'delivered_approval_pending' => 'attention', 'something_new' => 'attention'] as $raw => $want) {
        expect(CourierStatus::map('steadfast', $raw))->toBe($want);
    }
});

it('status mapping: pathao / redx free text', function () {
    foreach (['Delivered' => 'delivered', 'delivered' => 'delivered', 'Out for delivery' => 'in_transit', 'Undelivered' => 'attention', 'Delivery Failed' => 'attention', 'Return' => 'returned',
        'Returned to merchant' => 'returned', 'Pickup Cancelled' => 'cancelled', 'Partial delivery' => 'attention', 'On Hold' => 'attention', 'Pending' => 'in_transit',
        'Pickup Requested' => 'in_transit', 'In Transit' => 'in_transit', 'Parcel picked up' => 'in_transit'] as $raw => $want) {
        expect(CourierStatus::map('pathao', $raw))->toBe($want);
        expect(CourierStatus::map('redx', $raw))->toBe($want);
    }
});
