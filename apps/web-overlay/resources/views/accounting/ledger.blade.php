<x-layouts.app title="Ledger">
    {{-- JARVIS ONE: page-specific visual identity; existing actions preserved --}}
    <header class="jarvis-module-hero jarvis-accent-violet" data-jarvis-module="accounting" aria-label="Ledger">
      <div class="jarvis-module-hero__identity"><span class="jarvis-module-hero__glyph" aria-hidden="true">✦</span><div>
        <div class="jarvis-module-hero__eyebrow">JARVIS ONE <span aria-hidden="true">/</span> ACCOUNTING</div>
        <h1>Ledger</h1><p>Review transactions, balances and accounting workflows.</p>
      </div></div>
      <span class="jarvis-module-hero__section">Accounting Workspace</span>
    </header>

    <h2 class="text-xl font-semibold">Ledger</h2>
    @include('accounting._nav')
    <form method="GET" class="flex flex-wrap items-end gap-2 rounded-lg bg-white p-4 text-sm shadow">
        <select name="account" class="rounded border px-2 py-1"><option value="">Choose account</option>@foreach ($accounts as $a)<option value="{{ $a['id'] }}" @selected($sel === $a['id'])>{{ $a['code'] }} {{ $a['name_bn'] ?: $a['name'] }}</option>@endforeach</select>
        <input type="date" name="from" value="{{ $from }}" class="rounded border px-2 py-1"><input type="date" name="to" value="{{ $to }}" class="rounded border px-2 py-1"><button class="rounded bg-indigo-600 px-3 py-1 text-white">View</button></form>
    @if ($rows)<div class="overflow-x-auto rounded-lg bg-white shadow"><table class="min-w-full text-sm"><thead class="bg-gray-50 text-left text-xs text-gray-500"><tr><th class="px-3 py-2">Date</th><th class="px-3 py-2">Description</th><th class="px-3 py-2 text-right">Debit</th><th class="px-3 py-2 text-right">Credits</th><th class="px-3 py-2 text-right">Balance</th></tr></thead><tbody>
        @foreach ($rows as $r)<tr class="border-t"><td class="px-3 py-2">{{ $r['entry_date'] }}</td><td class="px-3 py-2">@if ($r['entry_id'])<a class="text-indigo-600" href="{{ route('accounting.show', trim($r['entry_id'])) }}">#{{ $r['number'] }}</a> @endif{{ $r['memo'] === 'Opening balance' ? 'Opening balance' : $r['memo'] }}</td><td class="px-3 py-2 text-right">{{ $r['debit_minor'] > 0 ? \App\Accounting\AccText::money((int) $r['debit_minor']) : '' }}</td><td class="px-3 py-2 text-right">{{ $r['credit_minor'] > 0 ? \App\Accounting\AccText::money((int) $r['credit_minor']) : '' }}</td><td class="px-3 py-2 text-right">{{ \App\Accounting\AccText::money((int) $r['balance_minor']) }}</td></tr>@endforeach</tbody></table></div>@endif
</x-layouts.app>
