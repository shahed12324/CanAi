<x-layouts.app :title="'Invoices '.$inv->number">
    {{-- JARVIS ONE: page-specific visual identity; existing actions preserved --}}
    <header class="jarvis-module-hero jarvis-accent-violet" data-jarvis-module="billing" aria-label="Billing — Invoice">
      <div class="jarvis-module-hero__identity"><span class="jarvis-module-hero__glyph" aria-hidden="true">✦</span><div>
        <div class="jarvis-module-hero__eyebrow">JARVIS ONE <span aria-hidden="true">/</span> BILLING</div>
        <h1>Billing — Invoice</h1><p>Manage subscriptions, invoices and payment history.</p>
      </div></div>
      <span class="jarvis-module-hero__section">Billing Workspace</span>
    </header>
    @php($P = \App\Billing\Present::class)
    @php($cur = trim($inv->currency))
    <div class="flex gap-3 print:hidden"><a class="text-sm text-indigo-600" href="{{ route('billing.invoices') }}">← List</a><button type="button" onclick="window.print()" class="ml-auto rounded bg-indigo-600 px-3 py-1 text-sm text-white">Print / save PDF</button></div>
    <article class="mx-auto max-w-2xl space-y-4 rounded-lg bg-white p-8 shadow print:shadow-none">
        <header class="flex justify-between">
            <div><h1 class="text-xl font-semibold">{{ $inv->kind === 'invoice' ? 'Tax invoice' : 'Credit note' }}</h1><p class="font-mono text-sm">{{ $inv->number }}</p><p class="text-sm text-gray-600">{{ \Illuminate\Support\Carbon::parse($inv->issued_at)->timezone('Asia/Dhaka')->format('d M Y') }}</p></div>
            <div class="text-right text-sm"><p class="font-medium">{{ $inv->seller['name'] ?? '' }}</p><p>{{ $inv->seller['address'] ?? '' }}</p>@if (! empty($inv->seller['bin']))<p>BIN: {{ $inv->seller['bin'] }}</p>@endif</div>
        </header>
        <section class="text-sm"><p class="text-gray-500">Customer</p><p class="font-medium">{{ $inv->buyer['name'] ?? '' }}</p><p>{{ $inv->buyer['address'] ?? '' }}</p>@if (! empty($inv->buyer['tax_id']))<p>BIN/TIN: {{ $inv->buyer['tax_id'] }}</p>@endif</section>
        <table class="w-full text-sm">
            <thead><tr class="border-b text-left text-gray-600"><th class="py-1">Description</th><th class="text-right">Amount</th></tr></thead>
            <tbody>@foreach ($inv->lines as $l)<tr class="border-b"><td class="py-1">{{ $l['description'] }}</td><td class="text-right tabular-nums">{{ ($l['amount_minor'] < 0 ? '− ' : '').$P::money(abs((int) $l['amount_minor']), $cur) }}</td></tr>@endforeach</tbody>
            <tfoot class="text-right">
                <tr><td class="py-1">Subtotal (excluding VAT)</td><td class="tabular-nums">{{ $P::money((int) $inv->subtotal_minor, $cur) }}</td></tr>
                <tr><td class="py-1">VAT ({{ $P::bp((int) $inv->vat_bp) }})</td><td class="tabular-nums">{{ $P::money((int) $inv->vat_minor, $cur) }}</td></tr>
                <tr class="font-semibold"><td class="py-1">{{ $inv->kind === 'invoice' ? 'Total' : 'Total refund' }}</td><td class="tabular-nums">{{ $P::money((int) $inv->total_minor, $cur) }}</td></tr>
            </tfoot>
        </table>
    </article>
</x-layouts.app>
