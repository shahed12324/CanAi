<x-layouts.app title="Order">
    {{-- JARVIS ONE: page-specific visual identity; existing actions preserved --}}
    <header class="jarvis-module-hero jarvis-accent-violet" data-jarvis-module="billing" aria-label="Order">
      <div class="jarvis-module-hero__identity"><span class="jarvis-module-hero__glyph" aria-hidden="true">✦</span><div>
        <div class="jarvis-module-hero__eyebrow">JARVIS ONE <span aria-hidden="true">/</span> BILLING</div>
        <h1>Order</h1><p>Manage subscriptions, invoices and payment history.</p>
      </div></div>
      <span class="jarvis-module-hero__section">Billing Workspace</span>
    </header>

    @php($P = \App\Billing\Present::class)
    <div class="flex items-center gap-3"><h1 class="text-xl font-semibold">Order history</h1><a class="ml-auto text-sm text-indigo-600" href="{{ route('billing.index') }}">← Billing</a></div>
    <div class="overflow-x-auto rounded-lg bg-white shadow"><table class="w-full text-sm">
        <thead class="bg-gray-50 text-left text-gray-600"><tr><th class="p-2">Item</th><th>Amount</th><th>Method</th><th>Status</th><th>Date</th></tr></thead>
        <tbody>@foreach ($rows as $o)
            <tr class="border-t"><td class="p-2">{{ $o->item_name }}</td><td class="tabular-nums">{{ $P::money((int) $o->total_minor, $o->currency) }}</td><td>{{ $o->gateway }}</td><td>{{ $P::ORDER_STATUS[$o->status] ?? $o->status }}</td><td>{{ \Illuminate\Support\Carbon::parse($o->created_at)->timezone('Asia/Dhaka')->format('d M Y, h:i A') }}</td></tr>
        @endforeach</tbody></table></div>
    {{ $rows->links() }}
</x-layouts.app>
