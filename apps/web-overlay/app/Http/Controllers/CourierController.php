<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Couriers\CourierRepo;
use App\Couriers\CourierSecrets;
use App\Couriers\CourierText;
use App\Couriers\ShipmentService;
use App\Orders\OrderRepo;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;

/** Courier accounts (owner/admin), booking a parcel, status refresh and the daily report. The rules live in the database functions. */
final class CourierController extends Controller
{
    public function __construct(
        private readonly CourierRepo $couriers,
        private readonly CourierSecrets $secrets,
        private readonly ShipmentService $shipments,
        private readonly OrderRepo $orders,
        private readonly TenantContext $tenant,
    ) {}

    private function need(Request $r, string $permission): void
    {
        abort_unless($r->user()->can($permission), 403);
    }

    private static function id(string $id): string
    {
        abort_unless(preg_match('/\A[0-9a-z]{26}\z/', $id) === 1, 404);

        return $id;
    }

    public function index(Request $request)
    {
        $this->need($request, 'couriers.manage');
        $byCourier = [];
        foreach ($this->couriers->accounts() as $a) {
            $byCourier[$a['courier']] = $a;
        }

        return view('couriers.index', ['accounts' => $byCourier, 'fields' => CourierSecrets::FIELDS]);
    }

    public function save(Request $request, string $courier)
    {
        $this->need($request, 'couriers.manage');
        abort_unless(isset(CourierSecrets::FIELDS[$courier]), 404);
        $rules = ['sandbox' => ['nullable', 'in:0,1'], 'store_id' => ['nullable', 'regex:/\A\d{1,12}\z/'], 'pickup_store_id' => ['nullable', 'regex:/\A\d{1,12}\z/']];
        foreach (CourierSecrets::FIELDS[$courier] as $f) {
            $rules[$f] = ['required', 'string', 'max:300'];
        }
        $d = $request->validate($rules);
        if ($courier === 'pathao' && ($d['store_id'] ?? '') === '') {
            return back()->withErrors(['store_id' => 'Pathao-Enter the Store ID of.'])->withInput($request->except(array_keys(array_flip(CourierSecrets::FIELDS[$courier]))));
        }
        $settings = [];
        if (($d['sandbox'] ?? '0') === '1' && $courier !== 'steadfast') {
            $settings['sandbox'] = true;
        }
        if ($courier === 'pathao') {
            $settings['store_id'] = $d['store_id'];
        }
        if ($courier === 'redx' && ($d['pickup_store_id'] ?? '') !== '') {
            $settings['pickup_store_id'] = $d['pickup_store_id'];
        }
        $sealed = $this->secrets->seal($courier, array_intersect_key($d, array_flip(CourierSecrets::FIELDS[$courier])));
        $this->couriers->saveAccount($this->tenant->idOrFail(), $courier, $sealed['cipher'], $sealed['version'], $settings);

        return back()->with('status', CourierText::name($courier).' Saved. The keys will not be shown again.');
    }

    public function toggle(Request $request, string $id)
    {
        $this->need($request, 'couriers.manage');
        $d = $request->validate(['status' => ['required', 'in:active,disabled']]);
        try {
            $this->couriers->setAccountStatus(self::id($id), $d['status']);
        } catch (\Illuminate\Database\QueryException) {
            abort(404);
        }

        return back()->with('status', $d['status'] === 'active' ? 'Turned on.' : 'Turned off.');
    }

    public function ship(Request $request, string $id)
    {
        $this->need($request, 'orders.manage');
        $d = $request->validate(['account_id' => ['required', 'regex:/\A[0-9a-z]{26}\z/'], 'area_id' => ['nullable', 'regex:/\A\d{1,10}\z/']]);
        $o = $this->orders->order(self::id($id)) ?? abort(404);
        $r = $this->shipments->book($o['order']['id'], $d['account_id'], (string) $request->user()->id, ($d['area_id'] ?? '') !== '' ? ['area_id' => $d['area_id']] : []);
        $err = CourierText::bookError($r['result'], $r['message']);

        return $err === null ? back()->with('status', 'The parcel was handed to the courier; order "Sent".') : back()->withErrors(['order' => $err]);
    }

    public function release(Request $request, string $id)
    {
        $this->need($request, 'orders.manage');
        $s = $this->couriers->shipment(self::id($id)) ?? abort(404);
        $row = collect($this->couriers->shipmentsOfOrder($s['order_id']))->firstWhere('id', $s['id']);
        if ($row === null || $s['status'] !== 'booking' || ! $row['stuck']) {
            return back()->withErrors(['order' => 'This cannot be released now (it can be done only when a booking has been stuck for over 10 minutes).']);
        }
        $this->shipments->release($s['id']);

        return back()->with('status', 'Released; you can book again.');
    }

    public function sync(Request $request, string $id)
    {
        $this->need($request, 'orders.manage');
        $s = $this->couriers->shipment(self::id($id)) ?? abort(404);
        $res = $this->shipments->sync($s['id']);

        return back()->with('status', 'The courier status was checked: '.match ($res) { 'changed' => 'Changed', 'same' => 'Same as before', 'mismatch' => 'Changed, but the order did not move on by itself (it had been changed by hand)', 'retry', 'attention' => 'No reply was received from the courier', 'terminal' => 'The parcel has ended', default => 'Nothing to do' });
    }

    public function shipmentsIndex(Request $request)
    {
        $this->need($request, 'orders.view');
        $f = in_array($request->query('f'), ['booking', 'booked', 'in_transit', 'attention', 'delivered', 'returned', 'cancelled', 'failed'], true) ? (string) $request->query('f') : null;

        return view('couriers.shipments', ['rows' => $this->couriers->shipments($f), 'filter' => $f]);
    }

    public function report(Request $request)
    {
        $this->need($request, 'orders.view');
        $today = now('Asia/Dhaka')->toDateString();

        return view('couriers.report', ['today' => $today, 'live' => $this->couriers->reportCalc($today), 'saved' => $this->couriers->reports(14)]);
    }
}
