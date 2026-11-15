<?php

use App\Couriers\CourierAdapter;
use App\Couriers\CourierException;
use App\Couriers\ReportText;
use App\Couriers\ShipmentService;
use Tests\Support\FakeCourierRepo;
use Tests\Support\FakeOrderRepo;

final class ScriptedCourier implements CourierAdapter
{
    public array $booked = [];

    public function __construct(public mixed $bookResult, public mixed $statusResult = 'delivered') {}

    public function book(array $parcel): array
    {
        $this->booked[] = $parcel;
        if ($this->bookResult instanceof Throwable) {
            throw $this->bookResult;
        }

        return $this->bookResult;
    }

    public function status(string $consignmentId, ?string $trackingCode): string
    {
        if ($this->statusResult instanceof Throwable) {
            throw $this->statusResult;
        }

        return $this->statusResult;
    }
}

function svc(ScriptedCourier $c, ?FakeCourierRepo $repo = null, bool $creds = true): array
{
    $repo ??= new FakeCourierRepo;
    $orders = new FakeOrderRepo;
    $orders->orderData = ['order' => ['id' => str_repeat('o', 26), 'number' => 7, 'customer_name' => 'রহিম', 'phone' => '+8801711223344', 'address' => 'House 12, Road 5, Dhanmondi', 'note' => null],
        'items' => [['qty' => 2], ['qty' => 1]], 'events' => []];

    return [new ShipmentService($repo, $orders, fn () => $creds ? ['secrets' => ['k' => 'v'], 'courier' => 'steadfast', 'settings' => [], 'status' => 'active'] : null, fn () => $c), $repo];
}

$GOOD = ['consignment_id' => 'C1', 'tracking_code' => 'T1', 'raw_status' => 'in_review'];

it('a good booking reserves first, calls the courier with the database COD, then records it', function () use ($GOOD) {
    $c = new ScriptedCourier($GOOD);
    [$s, $repo] = svc($c);
    expect($s->book(str_repeat('o', 26), str_repeat('a', 26), str_repeat('u', 26)))->toBe(['result' => 'ok', 'message' => null]);
    expect(array_column($repo->log, 0))->toBe(['reserve', 'booked']);
    $p = $c->booked[0];
    expect($p['cod'])->toBe(1310.5);                    // 131050 poisha, from the database — not from the screen
    expect($p['phone'])->toBe('01711223344');
    expect($p['qty'])->toBe(3);
    expect($p['invoice'])->toBe('ORD-7');
});

it('when the database says no, the courier is never called', function () use ($GOOD) {
    foreach (['bad_status', 'emergency', 'already_booked', 'no_account', 'account_disabled'] as $why) {
        $c = new ScriptedCourier($GOOD);
        $repo = new FakeCourierRepo;
        $repo->reserveResult = $why;
        [$s] = svc($c, $repo);
        expect($s->book(str_repeat('o', 26), str_repeat('a', 26), str_repeat('u', 26))['result'])->toBe($why);
        expect($c->booked)->toBe([]);
    }
});

it('a definite refusal frees the order; an uncertain one keeps the reservation', function () {
    [$s, $repo] = svc(new ScriptedCourier(new CourierException('courier refused (HTTP 422) bad phone')));
    $r = $s->book(str_repeat('o', 26), str_repeat('a', 26), str_repeat('u', 26));
    expect($r['result'])->toBe('refused');
    expect(array_column($repo->log, 0))->toBe(['reserve', 'fail']);
    [$s2, $repo2] = svc(new ScriptedCourier(new CourierException('network error', true)));
    expect($s2->book(str_repeat('o', 26), str_repeat('a', 26), str_repeat('u', 26))['result'])->toBe('uncertain');
    expect(array_column($repo2->log, 0))->toBe(['reserve']);                  // not failed, not booked: a person checks
    [$s3, $repo3] = svc(new ScriptedCourier(new RuntimeException('boom')));
    expect($s3->book(str_repeat('o', 26), str_repeat('a', 26), str_repeat('u', 26))['result'])->toBe('uncertain');
    expect(array_column($repo3->log, 0))->toBe(['reserve']);
});

it('unopenable credentials free the order without calling anyone', function () use ($GOOD) {
    $c = new ScriptedCourier($GOOD);
    [$s, $repo] = svc($c, null, false);
    expect($s->book(str_repeat('o', 26), str_repeat('a', 26), str_repeat('u', 26))['result'])->toBe('no_credentials');
    expect(array_column($repo->log, 0))->toBe(['reserve', 'fail']);
    expect($c->booked)->toBe([]);
});

function openShipment(FakeCourierRepo $repo, string $status = 'booked'): string
{
    $id = str_repeat('s', 26);
    $repo->shipments[$id] = ['id' => $id, 'order_id' => str_repeat('o', 26), 'account_id' => str_repeat('a', 26), 'courier' => 'steadfast', 'status' => $status, 'consignment_id' => 'C1', 'tracking_code' => 'T1'];

    return $id;
}

it('sync maps the courier word and applies it', function () {
    [$s, $repo] = svc(new ScriptedCourier([], 'partial_delivered'));
    $id = openShipment($repo);
    expect($s->sync($id))->toBe('changed');
    expect($repo->called('apply'))->toBe([['attention', 'partial_delivered']]);
});

it('sync records a failed check instead of guessing, and skips finished parcels', function () {
    [$s, $repo] = svc(new ScriptedCourier([], new CourierException('courier HTTP 503', true)));
    $id = openShipment($repo);
    expect($s->sync($id))->toBe('retry');
    expect($repo->called('apply'))->toBe([]);
    expect(count($repo->called('checkFailed')))->toBe(1);
    [$s2, $repo2] = svc(new ScriptedCourier([], 'delivered'));
    $done = openShipment($repo2, 'delivered');
    expect($s2->sync($done))->toBe('skipped');
    expect($s2->sync(str_repeat('z', 26)))->toBe('skipped');
});

it('money and phone helpers', function () {
    expect(ShipmentService::taka(131000))->toBe(1310);
    expect(ShipmentService::taka(131050))->toBe(1310.5);
    expect(ShipmentService::localPhone('+8801711223344'))->toBe('01711223344');
    expect(ShipmentService::localPhone('weird'))->toBe('weird');
});

it('the daily report reads well and warns only when something needs attention', function () {
    $d = ['created' => ['count' => 5, 'value_minor' => 560000, 'by_agent' => 2, 'risky' => 1], 'confirmed' => 4, 'shipped' => 3, 'delivered' => ['count' => 2, 'value_minor' => 250000], 'cancelled' => 1, 'returned' => 0,
        'open' => ['pending' => 1, 'shipped' => 3], 'cod_pending' => ['count' => 3, 'value_minor' => 330000], 'attention' => 0, 'couriers' => [['courier' => 'steadfast', 'delivered' => 8, 'bad' => 2]]];
    $t = implode("\n", ReportText::lines($d));
    expect(str_contains($t, '৫টি'))->toBeTrue();
    expect(str_contains($t, 'AI এজেন্টের ২টি'))->toBeTrue();
    expect(str_contains($t, 'সফলতা ৮০%'))->toBeTrue();
    expect(str_contains($t, '⚠'))->toBeFalse();
    $d['attention'] = 2;
    expect(str_contains(implode("\n", ReportText::lines($d)), '⚠ ২টি'))->toBeTrue();
    expect(count(ReportText::lines([])) >= 5)->toBeTrue();                  // an empty report still renders
});
