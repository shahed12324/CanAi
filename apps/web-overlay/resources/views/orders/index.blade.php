<x-layouts.app title="Order">
    {{-- JARVIS ONE: page-specific visual identity; existing actions preserved --}}
    <header class="jarvis-module-hero jarvis-accent-violet" data-jarvis-module="orders" aria-label="Order">
      <div class="jarvis-module-hero__identity"><span class="jarvis-module-hero__glyph" aria-hidden="true">✦</span><div>
        <div class="jarvis-module-hero__eyebrow">JARVIS ONE <span aria-hidden="true">/</span> ORDERS</div>
        <h1>Order</h1><p>Track orders, fulfillment and customer activity.</p>
      </div></div>
      <span class="jarvis-module-hero__section">Orders Workspace</span>
    </header>

    <div class="flex flex-wrap items-center justify-between gap-2">
        <h2 class="text-xl font-semibold">Order</h2>
        <div class="flex gap-3 text-sm"><a class="text-indigo-600" href="{{ route('shipments.index') }}">Parcels</a><a class="text-indigo-600" href="{{ route('orders.report') }}">Daily report</a>@can('couriers.manage')<a class="text-indigo-600" href="{{ route('couriers.index') }}">Courier</a>@endcan<a class="text-indigo-600" href="{{ route('orders.settings') }}">Settings</a>@if ($canManage)<a class="rounded bg-indigo-600 px-3 py-1 text-white" href="{{ route('orders.create') }}">New order</a>@endif</div>
    </div>
    <nav class="flex flex-wrap gap-2 text-sm">
        <a class="rounded px-2 py-1 {{ $filter === null ? 'bg-indigo-600 text-white' : 'bg-gray-100' }}" href="{{ route('orders.index') }}">All</a>
        @foreach ($filters as $f)<a class="rounded px-2 py-1 {{ $filter === $f ? 'bg-indigo-600 text-white' : 'bg-gray-100' }}" href="{{ route('orders.index', ['f' => $f]) }}">{{ \App\Orders\OrderText::statusBn($f) }}</a>@endforeach
    </nav>
    <div class="overflow-x-auto rounded-lg bg-white shadow">
        <table class="min-w-full text-sm">
            <thead class="bg-gray-50 text-left text-xs text-gray-500"><tr><th class="px-3 py-2">#</th><th class="px-3 py-2">Customer</th><th class="px-3 py-2">Total</th><th class="px-3 py-2">Status</th><th class="px-3 py-2">Risk</th><th class="px-3 py-2">Source</th><th class="px-3 py-2">Period</th></tr></thead>
            <tbody>
            @forelse ($rows as $r)
                <tr class="border-t"><td class="px-3 py-2"><a class="text-indigo-600" href="{{ route('orders.show', $r['id']) }}">#{{ $r['number'] }}</a></td>
                    <td class="px-3 py-2">{{ $r['customer_name'] }}<span class="block text-xs text-gray-500">{{ $r['phone'] }}</span></td>
                    <td class="px-3 py-2">{{ \App\Orders\OrderText::money((int) $r['total_minor'], $r['currency']) }}</td>
                    <td class="px-3 py-2">{{ \App\Orders\OrderText::statusBn($r['status']) }}</td>
                    <td class="px-3 py-2">@if ($r['needs_review'])<span class="rounded bg-red-100 px-2 py-0.5 text-xs text-red-700">Needs verification ({{ $r['risk_score'] }})</span>@else<span class="text-xs text-gray-500">{{ $r['risk_score'] }}</span>@endif</td>
                    <td class="px-3 py-2 text-xs">{{ $r['source'] === 'agent' ? 'AI' : 'Employee' }}</td>
                    <td class="px-3 py-2 text-xs text-gray-500">{{ \Illuminate\Support\Carbon::parse($r['created_at'])->format('d M H:i') }}</td></tr>
            @empty<tr><td colspan="7" class="px-3 py-6 text-center text-gray-500">No orders.</td></tr>@endforelse
            </tbody>
        </table>
    </div>
</x-layouts.app>
