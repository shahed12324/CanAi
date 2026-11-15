<x-layouts.app title="Parcels">
    {{-- JARVIS ONE: page-specific visual identity; existing actions preserved --}}
    <header class="jarvis-module-hero jarvis-accent-violet" data-jarvis-module="couriers" aria-label="Parcels">
      <div class="jarvis-module-hero__identity"><span class="jarvis-module-hero__glyph" aria-hidden="true">✦</span><div>
        <div class="jarvis-module-hero__eyebrow">JARVIS ONE <span aria-hidden="true">/</span> COURIERS</div>
        <h1>Parcels</h1>
      </div></div>
      <span class="jarvis-module-hero__section">Couriers Workspace</span>
    </header>

    <div class="flex items-center justify-between"><h2 class="text-xl font-semibold">Parcels</h2><a class="text-sm text-indigo-600" href="{{ route('orders.index') }}">← Order</a></div>
    <nav class="flex flex-wrap gap-2 text-sm">
        <a class="rounded px-2 py-1 {{ $filter === null ? 'bg-indigo-600 text-white' : 'bg-gray-100' }}" href="{{ route('shipments.index') }}">All</a>
        @foreach (['attention', 'booked', 'in_transit', 'delivered', 'returned', 'cancelled', 'booking', 'failed'] as $f)<a class="rounded px-2 py-1 {{ $filter === $f ? 'bg-indigo-600 text-white' : 'bg-gray-100' }}" href="{{ route('shipments.index', ['f' => $f]) }}">{{ \App\Couriers\CourierText::statusBn($f) }}</a>@endforeach
    </nav>
    <div class="overflow-x-auto rounded-lg bg-white shadow"><table class="min-w-full text-sm">
        <thead class="bg-gray-50 text-left text-xs text-gray-500"><tr><th class="px-3 py-2">Order</th><th class="px-3 py-2">Customer</th><th class="px-3 py-2">Courier</th><th class="px-3 py-2">Consignment</th><th class="px-3 py-2">Status</th><th class="px-3 py-2">COD</th></tr></thead>
        <tbody>@forelse ($rows as $r)<tr class="border-t"><td class="px-3 py-2"><a class="text-indigo-600" href="{{ route('orders.show', $r['order_id']) }}">#{{ $r['number'] }}</a></td><td class="px-3 py-2">{{ $r['customer_name'] }}</td><td class="px-3 py-2">{{ \App\Couriers\CourierText::name($r['courier']) }}</td><td class="px-3 py-2 font-mono text-xs">{{ $r['consignment_id'] }}</td><td class="px-3 py-2">{{ \App\Couriers\CourierText::statusBn($r['status']) }}@if ($r['raw_status'])<span class="text-xs text-gray-500"> ({{ $r['raw_status'] }})</span>@endif</td><td class="px-3 py-2">{{ \App\Orders\OrderText::money((int) $r['cod_minor']) }}</td></tr>
        @empty<tr><td colspan="6" class="px-3 py-6 text-center text-gray-500">No parcels.</td></tr>@endforelse</tbody></table></div>
</x-layouts.app>
