<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Orders\OrderRepo;
use App\Orders\OrderText;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;

/** Staff side of orders. All rules (price, stock, discount limit, risk, status path) live in the database functions. */
final class OrderController extends Controller
{
    private const FILTERS = ['pending', 'confirmed', 'packed', 'shipped', 'delivered', 'cancelled', 'returned'];

    public function __construct(private readonly OrderRepo $orders, private readonly TenantContext $tenant) {}

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
        $this->need($request, 'orders.view');
        $f = in_array($request->query('f'), self::FILTERS, true) ? (string) $request->query('f') : null;

        return view('orders.index', ['rows' => $this->orders->orders($f), 'filter' => $f, 'filters' => self::FILTERS, 'canManage' => $request->user()->can('orders.manage')]);
    }

    public function create(Request $request)
    {
        $this->need($request, 'orders.manage');

        return view('orders.create', ['variants' => $this->orders->sellable(), 'settings' => $this->orders->settings()]);
    }

    public function store(Request $request)
    {
        $this->need($request, 'orders.manage');
        $d = $request->validate([
            'customer_name' => ['required', 'string', 'max:120'], 'phone' => ['required', 'string', 'max:30'], 'address' => ['required', 'string', 'max:500'],
            'zone' => ['required', 'in:inside,outside'], 'note' => ['nullable', 'string', 'max:300'], 'discount_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'lines' => ['required', 'array', 'max:20'],
        ]);
        $items = OrderText::items($d['lines']);
        $r = $this->orders->create($this->tenant->idOrFail(), (string) $request->user()->id, 'staff', null, $d['customer_name'], $d['phone'], $d['address'], $d['zone'], $d['note'] ?? null, $items, (float) ($d['discount_pct'] ?? 0));
        $err = OrderText::createError($r['result']);
        if ($err !== null) {
            return back()->withErrors(['order' => $err])->withInput();
        }

        return redirect()->route('orders.show', $r['order_id'])->with('status', 'Order #'.$r['number'].' Created'.($r['needs_review'] ? ' — Risk is high, verify by phone before confirming.' : '.'));
    }

    public function show(Request $request, string $id)
    {
        $this->need($request, 'orders.view');
        $o = $this->orders->order(self::id($id)) ?? abort(404);

        $couriers = app(\App\Couriers\CourierRepo::class);

        return view('orders.show', $o + ['canManage' => $request->user()->can('orders.manage'), 'shipments' => $couriers->shipmentsOfOrder($o['order']['id']),
            'accounts' => array_values(array_filter($couriers->accounts(), fn ($a) => $a['status'] === 'active'))]);
    }

    public function transition(Request $request, string $id)
    {
        $this->need($request, 'orders.manage');
        $d = $request->validate(['to' => ['required', 'in:confirmed,packed,shipped,delivered,cancelled,returned'], 'note' => ['nullable', 'string', 'max:200']]);
        $o = $this->orders->order(self::id($id)) ?? abort(404);
        $err = OrderText::transitionError($this->orders->transition($o['order']['id'], (string) $request->user()->id, $d['to'], $d['note'] ?? null));

        return $err === null ? back()->with('status', 'Order status: '.OrderText::statusBn($d['to'])) : back()->withErrors(['order' => $err]);
    }

    public function settings(Request $request)
    {
        $this->need($request, 'orders.view');

        return view('orders.settings', ['s' => $this->orders->settings(), 'canEdit' => $request->user()->can('orders.settings')]);
    }

    public function saveSettings(Request $request)
    {
        $this->need($request, 'orders.settings');
        $d = $request->validate(['max_discount_pct' => ['required', 'integer', 'min:0', 'max:50'], 'delivery_inside' => ['required', 'numeric', 'min:0', 'max:100000'],
            'delivery_outside' => ['required', 'numeric', 'min:0', 'max:100000'], 'review_score' => ['required', 'integer', 'min:1', 'max:100'], 'chat_orders' => ['nullable', 'in:0,1']]);
        $this->orders->saveSettings($this->tenant->idOrFail(), (int) $d['max_discount_pct'], (int) round($d['delivery_inside'] * 100), (int) round($d['delivery_outside'] * 100), (int) $d['review_score'], ($d['chat_orders'] ?? '0') === '1');

        return back()->with('status', 'Order settings were saved.');
    }
}
