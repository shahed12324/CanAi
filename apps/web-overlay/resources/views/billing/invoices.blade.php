<x-layouts.app title="Invoices">
    {{-- JARVIS ONE: page-specific visual identity; existing actions preserved --}}
    <header class="jarvis-module-hero jarvis-accent-violet" data-jarvis-module="billing" aria-label="Invoices">
      <div class="jarvis-module-hero__identity"><span class="jarvis-module-hero__glyph" aria-hidden="true">✦</span><div>
        <div class="jarvis-module-hero__eyebrow">JARVIS ONE <span aria-hidden="true">/</span> BILLING</div>
        <h1>Invoices</h1><p>Manage subscriptions, invoices and payment history.</p>
      </div></div>
      <span class="jarvis-module-hero__section">Billing Workspace</span>
    </header>

    @php($P = \App\Billing\Present::class)
    <div class="flex items-center gap-3"><h1 class="text-xl font-semibold">Invoices and credit notes</h1><a class="ml-auto text-sm text-indigo-600" href="{{ route('billing.index') }}">← Billing</a></div>
    <div class="overflow-x-auto rounded-lg bg-white shadow"><table class="w-full text-sm">
        <thead class="bg-gray-50 text-left text-gray-600"><tr><th class="p-2">Number</th><th>Type</th><th>Amount</th><th>Date</th><th></th></tr></thead>
        <tbody>@foreach ($rows as $i)
            <tr class="border-t"><td class="p-2 font-mono">{{ $i->number }}</td><td>{{ $i->kind === 'invoice' ? 'VAT Invoices' : 'Credit note (refund)' }}</td><td class="tabular-nums">{{ $P::money((int) $i->total_minor, $i->currency) }}</td><td>{{ \Illuminate\Support\Carbon::parse($i->issued_at)->timezone('Asia/Dhaka')->format('d M Y') }}</td><td><a class="text-indigo-600" href="{{ route('billing.invoice', trim($i->id)) }}">View / print</a></td></tr>
        @endforeach</tbody></table></div>
    {{ $rows->links() }}
</x-layouts.app>
