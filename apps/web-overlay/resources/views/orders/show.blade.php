<x-layouts.app title="Order">
    {{-- JARVIS ONE: page-specific visual identity; existing actions preserved --}}
    <header class="jarvis-module-hero jarvis-accent-violet" data-jarvis-module="orders" aria-label="Order">
      <div class="jarvis-module-hero__identity"><span class="jarvis-module-hero__glyph" aria-hidden="true">✦</span><div>
        <div class="jarvis-module-hero__eyebrow">JARVIS ONE <span aria-hidden="true">/</span> ORDERS</div>
        <h1>Order</h1><p>Track orders, fulfillment and customer activity.</p>
      </div></div>
      <span class="jarvis-module-hero__section">Orders Workspace</span>
    </header>

    @php($o = $order)
    <div class="flex flex-wrap items-center justify-between gap-2">
        <h2 class="text-xl font-semibold">Order #{{ $o['number'] }} <span class="ml-1 rounded bg-gray-100 px-2 py-0.5 text-xs font-normal">{{ \App\Orders\OrderText::statusBn($o['status']) }}</span></h2>
        <a class="text-sm text-indigo-600" href="{{ route('orders.index') }}">← Order</a>
    </div>
    @if (session('status'))<p class="rounded bg-green-50 p-3 text-sm text-green-700">{{ session('status') }}</p>@endif
    @error('order')<p class="rounded bg-red-50 p-3 text-sm text-red-700">{{ $message }}</p>@enderror
    @if ($o['needs_review'] && $o['status'] === 'pending')
        <div class="rounded bg-red-50 p-3 text-sm text-red-800">⚠ Fake-order risk {{ $o['risk_score'] }}/100 — call and verify before confirming.</div>
    @endif
    <section class="grid gap-4 rounded-lg bg-white p-5 text-sm shadow sm:grid-cols-2">
        <div><b>{{ $o['customer_name'] }}</b><br>{{ $o['phone'] }}<br><span class="whitespace-pre-wrap">{{ $o['address'] }}</span><br><span class="text-gray-500">{{ $o['zone'] === 'inside' ? 'Inside Dhaka' : 'Outside Dhaka' }} · Cash on delivery</span>@if ($o['note'])<br><i>{{ $o['note'] }}</i>@endif</div>
        <div>Source: {{ $o['source'] === 'agent' ? 'AI Agent' : 'Employee' }}@if ($o['conversation_id']) · <a class="text-indigo-600" href="{{ route('inbox.show', $o['conversation_id']) }}">View chat</a>@endif<br>
            Risk: {{ $o['risk_score'] }}@if ($o['risk_reasons'])<ul class="list-disc pl-5 text-xs text-gray-600">@foreach ($o['risk_reasons'] as $c)<li>{{ \App\Orders\OrderText::riskBn($c) }}</li>@endforeach</ul>@endif</div>
    </section>
    <section class="overflow-x-auto rounded-lg bg-white shadow">
        <table class="min-w-full text-sm"><thead class="bg-gray-50 text-left text-xs text-gray-500"><tr><th class="px-3 py-2">Product</th><th class="px-3 py-2">Price</th><th class="px-3 py-2">Quantity</th><th class="px-3 py-2 text-right">Total</th></tr></thead><tbody>
            @foreach ($items as $i)<tr class="border-t"><td class="px-3 py-2">{{ $i['title'] }}</td><td class="px-3 py-2">{{ \App\Orders\OrderText::money((int) $i['unit_price_minor'], $o['currency']) }}</td><td class="px-3 py-2">{{ $i['qty'] }}</td><td class="px-3 py-2 text-right">{{ \App\Orders\OrderText::money((int) $i['line_total_minor'], $o['currency']) }}</td></tr>@endforeach
            <tr class="border-t"><td colspan="3" class="px-3 py-1 text-right">Subtotal</td><td class="px-3 py-1 text-right">{{ \App\Orders\OrderText::money((int) $o['subtotal_minor'], $o['currency']) }}</td></tr>
            @if ($o['discount_minor'] > 0)<tr><td colspan="3" class="px-3 py-1 text-right">Discount ({{ (float) $o['discount_pct'] }}%)</td><td class="px-3 py-1 text-right">−{{ \App\Orders\OrderText::money((int) $o['discount_minor'], $o['currency']) }}</td></tr>@endif
            <tr><td colspan="3" class="px-3 py-1 text-right">Delivery</td><td class="px-3 py-1 text-right">{{ \App\Orders\OrderText::money((int) $o['delivery_minor'], $o['currency']) }}</td></tr>
            <tr class="font-semibold"><td colspan="3" class="px-3 py-2 text-right">Customer pays (COD)</td><td class="px-3 py-2 text-right">{{ \App\Orders\OrderText::money((int) $o['total_minor'], $o['currency']) }}</td></tr></tbody></table>
    </section>
    @if ($canManage && \App\Orders\OrderText::actions($o['status']))
        <section class="flex flex-wrap items-center gap-2 rounded-lg bg-white p-5 text-sm shadow">
            @foreach (\App\Orders\OrderText::actions($o['status']) as [$to, $label])
                <form method="POST" action="{{ route('orders.transition', $o['id']) }}" class="flex gap-2">@csrf<input type="hidden" name="to" value="{{ $to }}">
                    @if (in_array($to, ['cancelled', 'returned'], true))<input name="note" maxlength="200" placeholder="Reason" class="rounded border px-2 py-1">@endif
                    <button class="rounded px-3 py-1 {{ in_array($to, ['cancelled', 'returned'], true) ? 'border' : 'bg-indigo-600 text-white' }}">{{ $label }}</button></form>
            @endforeach
        </section>
    @endif
    @if ($shipments || ($canManage && in_array($o['status'], ['confirmed', 'packed'], true)))
        <section class="space-y-3 rounded-lg bg-white p-5 text-sm shadow"><h3 class="font-semibold">Courier</h3>
            @foreach ($shipments as $s)
                <div class="rounded border p-3">
                    <b>{{ \App\Couriers\CourierText::name($s['courier']) }}</b> — {{ \App\Couriers\CourierText::statusBn($s['status']) }}
                    @if ($s['consignment_id'])· <span class="font-mono">{{ $s['consignment_id'] }}</span>@endif
                    · COD {{ \App\Orders\OrderText::money((int) $s['cod_minor'], $o['currency']) }}
                    @if ($s['raw_status'])<span class="text-gray-500">(Courier says: {{ $s['raw_status'] }})</span>@endif
                    @if ($s['error'])<div class="text-xs text-red-700">{{ $s['error'] }}</div>@endif
                    @if ($canManage && in_array($s['status'], ['booked', 'in_transit', 'attention'], true))
                        <form method="POST" action="{{ route('shipments.sync', $s['id']) }}" class="mt-2">@csrf<button class="rounded border px-2 py-1 text-xs">Check status now</button></form>
                    @endif
                    @if ($canManage && $s['stuck'])
                        <form method="POST" action="{{ route('shipments.release', $s['id']) }}" class="mt-2">@csrf<button class="rounded border px-2 py-1 text-xs">Release (parcel not with courier)</button></form>
                    @endif
                </div>
            @endforeach
            @if ($canManage && in_array($o['status'], ['confirmed', 'packed'], true))
                @if ($accounts)
                    <form method="POST" action="{{ route('orders.ship', $o['id']) }}" class="flex flex-wrap items-center gap-2">@csrf
                        <select name="account_id" class="rounded border px-2 py-1">@foreach ($accounts as $a)<option value="{{ $a['id'] }}">{{ \App\Couriers\CourierText::name($a['courier']) }}</option>@endforeach</select>
                        <input name="area_id" inputmode="numeric" placeholder="RedX area id (optional)" class="rounded border px-2 py-1">
                        <button class="rounded bg-indigo-600 px-3 py-1 text-white">Hand to courier</button>
                    </form>
                @else
                    <p class="text-gray-500">No courier is set @can('couriers.manage') — <a class="text-indigo-600" href="{{ route('couriers.index') }}">Set up</a>@endcan.</p>
                @endif
            @endif
        </section>
    @endif
    <section class="rounded-lg bg-white p-5 text-sm shadow"><h3 class="mb-2 font-semibold">History</h3><ul class="space-y-1 text-gray-700">
        @foreach ($events as $e)<li>{{ \Illuminate\Support\Carbon::parse($e['created_at'])->format('d M H:i') }} — {{ $e['from_status'] ? \App\Orders\OrderText::statusBn($e['from_status']).' → ' : '' }}{{ \App\Orders\OrderText::statusBn($e['to_status']) }}@if ($e['note']) <i>({{ $e['note'] }})</i>@endif</li>@endforeach</ul></section>
</x-layouts.app>
