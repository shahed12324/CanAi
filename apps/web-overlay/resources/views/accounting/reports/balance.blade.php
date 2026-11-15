<x-layouts.app title="Balance sheet">
    {{-- JARVIS ONE: page-specific visual identity; existing actions preserved --}}
    <header class="jarvis-module-hero jarvis-accent-violet" data-jarvis-module="accounting" aria-label="Balance sheet">
      <div class="jarvis-module-hero__identity"><span class="jarvis-module-hero__glyph" aria-hidden="true">✦</span><div>
        <div class="jarvis-module-hero__eyebrow">JARVIS ONE <span aria-hidden="true">/</span> ACCOUNTING</div>
        <h1>Balance sheet</h1><p>Review transactions, balances and accounting workflows.</p>
      </div></div>
      <span class="jarvis-module-hero__section">Accounting Workspace</span>
    </header>

    @php($m = fn ($v) => \App\Accounting\AccText::money((int) $v))
    <h2 class="text-xl font-semibold">Balance sheet <span class="text-sm font-normal text-gray-500">{{ $asof }} As of date</span></h2>
    @include('accounting._nav')
    <form method="GET" class="flex items-end gap-2 text-sm print:hidden"><label>Date <input type="date" name="asof" value="{{ $asof }}" class="rounded border px-1 py-1"></label><button class="rounded bg-indigo-600 px-3 py-1 text-white">View</button><button type="button" onclick="window.print()" class="rounded border px-3 py-1">Print</button></form>
    <p class="rounded px-3 py-2 text-sm {{ $rep['balanced'] ? 'bg-green-50 text-green-800' : 'bg-red-50 text-red-800' }}">{{ $rep['balanced'] ? '✔ Balanced: Assets = Liabilities + Equity' : '✘ Not balanced — this should not happen, tell support' }}</p>
    <div class="grid gap-4 lg:grid-cols-2">
        <div class="overflow-x-auto rounded-lg bg-white shadow"><table class="min-w-full text-sm"><tbody>
            <tr class="bg-gray-50"><th colspan="2" class="px-3 py-2 text-left">Asset</th></tr>
            @foreach ($rep['asset'] as $r)<tr class="border-t"><td class="px-3 py-1">{{ $r['code'] }} {{ $r['name_bn'] ?: $r['name'] }}</td><td class="px-3 py-1 text-right">{{ $m($r['amount_minor']) }}</td></tr>@endforeach
            <tr class="border-t-2 font-bold"><td class="px-3 py-2">Total assets</td><td class="px-3 py-2 text-right">{{ $m($rep['totals']['assets']) }}</td></tr></tbody></table></div>
        <div class="overflow-x-auto rounded-lg bg-white shadow"><table class="min-w-full text-sm"><tbody>
            <tr class="bg-gray-50"><th colspan="2" class="px-3 py-2 text-left">Liabilities</th></tr>
            @foreach ($rep['liability'] as $r)<tr class="border-t"><td class="px-3 py-1">{{ $r['code'] }} {{ $r['name_bn'] ?: $r['name'] }}</td><td class="px-3 py-1 text-right">{{ $m($r['amount_minor']) }}</td></tr>@endforeach
            <tr class="border-t font-semibold"><td class="px-3 py-1">Total liabilities</td><td class="px-3 py-1 text-right">{{ $m($rep['totals']['liabilities']) }}</td></tr>
            <tr class="bg-gray-50"><th colspan="2" class="px-3 py-2 text-left">Owner’s equity</th></tr>
            @foreach ($rep['equity'] as $r)<tr class="border-t"><td class="px-3 py-1">{{ $r['code'] }} {{ $r['name_bn'] ?: $r['name'] }}</td><td class="px-3 py-1 text-right">{{ $m($r['amount_minor']) }}</td></tr>@endforeach
            <tr class="border-t"><td class="px-3 py-1">Profit/(loss) to date <span class="text-xs text-gray-500">(Not closed at year end)</span></td><td class="px-3 py-1 text-right">{{ $m($rep['earnings']) }}</td></tr>
            <tr class="border-t font-semibold"><td class="px-3 py-1">Total owner’s equity</td><td class="px-3 py-1 text-right">{{ $m($rep['totals']['equity']) }}</td></tr>
            <tr class="border-t-2 font-bold"><td class="px-3 py-2">Total liabilities + equity</td><td class="px-3 py-2 text-right">{{ $m($rep['totals']['liabilities_equity']) }}</td></tr></tbody></table></div>
    </div>
</x-layouts.app>
