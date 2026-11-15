<x-layouts.app title="Receivables/Payables">
    {{-- JARVIS ONE: page-specific visual identity; existing actions preserved --}}
    <header class="jarvis-module-hero jarvis-accent-violet" data-jarvis-module="accounting" aria-label="Receivables/Payables">
      <div class="jarvis-module-hero__identity"><span class="jarvis-module-hero__glyph" aria-hidden="true">✦</span><div>
        <div class="jarvis-module-hero__eyebrow">JARVIS ONE <span aria-hidden="true">/</span> ACCOUNTING</div>
        <h1>Receivables/Payables</h1><p>Review transactions, balances and accounting workflows.</p>
      </div></div>
      <span class="jarvis-module-hero__section">Accounting Workspace</span>
    </header>

    <h2 class="text-xl font-semibold">{{ $kind === 'invoice' ? 'Receivable (from customers)' : 'Payable (to suppliers)' }} — By age</h2>
    @include('accounting._nav')
    <form method="GET" class="flex flex-wrap items-end gap-2 text-sm"><select name="kind" class="rounded border px-2 py-1"><option value="invoice" @selected($kind === 'invoice')>Receivable</option><option value="bill" @selected($kind === 'bill')>Payable</option></select><input type="date" name="asof" value="{{ $asof }}" class="rounded border px-2 py-1"><button class="rounded bg-indigo-600 px-3 py-1 text-white">View</button></form>
    <div class="grid gap-2 sm:grid-cols-5">@foreach ($buckets as $b => $v)<div class="rounded-lg bg-white p-3 text-sm shadow"><div class="text-xs text-gray-500">{{ \App\Accounting\AccText::bucketBn($b) }}</div><div class="font-semibold">{{ \App\Accounting\AccText::money($v) }}</div></div>@endforeach</div>
    <div class="overflow-x-auto rounded-lg bg-white shadow"><table class="min-w-full text-sm"><thead class="bg-gray-50 text-left text-xs text-gray-500"><tr><th class="px-3 py-2">Number</th><th class="px-3 py-2">Party</th><th class="px-3 py-2">Due date</th><th class="px-3 py-2 text-right">Outstanding</th><th class="px-3 py-2">Overdue</th></tr></thead><tbody>
        @forelse ($rows as $r)<tr class="border-t"><td class="px-3 py-2"><a class="text-indigo-600" href="{{ route('accounting.doc', $r['doc_id']) }}">{{ $r['number'] }}</a></td><td class="px-3 py-2">{{ $r['party_name'] }}</td><td class="px-3 py-2">{{ $r['due_date'] }}</td><td class="px-3 py-2 text-right">{{ \App\Accounting\AccText::money((int) $r['balance_minor']) }}</td><td class="px-3 py-2">{{ $r['days_overdue'] > 0 ? $r['days_overdue'].' days' : '' }}</td></tr>
        @empty<tr><td colspan="5" class="px-3 py-6 text-center text-gray-500">Nothing outstanding.</td></tr>@endforelse</tbody></table></div>
</x-layouts.app>
