<x-layouts.app title="Profit and loss">
    {{-- JARVIS ONE: page-specific visual identity; existing actions preserved --}}
    <header class="jarvis-module-hero jarvis-accent-violet" data-jarvis-module="accounting" aria-label="Profit and loss">
      <div class="jarvis-module-hero__identity"><span class="jarvis-module-hero__glyph" aria-hidden="true">✦</span><div>
        <div class="jarvis-module-hero__eyebrow">JARVIS ONE <span aria-hidden="true">/</span> ACCOUNTING</div>
        <h1>Profit and loss</h1><p>Review transactions, balances and accounting workflows.</p>
      </div></div>
      <span class="jarvis-module-hero__section">Accounting Workspace</span>
    </header>

    @php($m = fn ($v) => \App\Accounting\AccText::money((int) $v))
    <h2 class="text-xl font-semibold">Profit and loss statement <span class="text-sm font-normal text-gray-500">{{ $from }} from {{ $to }}</span></h2>
    @include('accounting._nav')
    @include('accounting.reports._period')
    <div class="overflow-x-auto rounded-lg bg-white shadow"><table class="min-w-full text-sm"><tbody>
        @foreach ([['income', 'Income', 'Total earnings'], ['cogs', 'Cost of goods sold', 'Total cost of goods']] as [$k, $title, $totalLabel])
            <tr class="bg-gray-50"><th colspan="2" class="px-3 py-2 text-left">{{ $title }}</th></tr>
            @foreach ($rep[$k] as $r)<tr class="border-t"><td class="px-3 py-1">{{ $r['code'] }} {{ $r['name_bn'] ?: $r['name'] }}</td><td class="px-3 py-1 text-right">{{ $m($r['amount_minor']) }}</td></tr>@endforeach
            <tr class="border-t font-semibold"><td class="px-3 py-1">{{ $totalLabel }}</td><td class="px-3 py-1 text-right">{{ $m($rep['totals'][$k]) }}</td></tr>
            @if ($k === 'cogs')<tr class="border-t bg-indigo-50 font-semibold"><td class="px-3 py-2">Gross profit</td><td class="px-3 py-2 text-right">{{ $m($rep['totals']['gross']) }}</td></tr>@endif
        @endforeach
        <tr class="bg-gray-50"><th colspan="2" class="px-3 py-2 text-left">Operating expenses</th></tr>
        @foreach ($rep['opex'] as $r)<tr class="border-t"><td class="px-3 py-1">{{ $r['code'] }} {{ $r['name_bn'] ?: $r['name'] }}</td><td class="px-3 py-1 text-right">{{ $m($r['amount_minor']) }}</td></tr>@endforeach
        <tr class="border-t font-semibold"><td class="px-3 py-1">Total operating expenses</td><td class="px-3 py-1 text-right">{{ $m($rep['totals']['opex']) }}</td></tr>
        <tr class="border-t bg-indigo-50 font-semibold"><td class="px-3 py-2">Operating profit</td><td class="px-3 py-2 text-right">{{ $m($rep['totals']['operating']) }}</td></tr>
        @if ($rep['nonop'])<tr class="bg-gray-50"><th colspan="2" class="px-3 py-2 text-left">Non-operating expenses</th></tr>
            @foreach ($rep['nonop'] as $r)<tr class="border-t"><td class="px-3 py-1">{{ $r['code'] }} {{ $r['name_bn'] ?: $r['name'] }}</td><td class="px-3 py-1 text-right">{{ $m($r['amount_minor']) }}</td></tr>@endforeach @endif
        <tr class="border-t-2 text-base font-bold {{ $rep['totals']['net'] < 0 ? 'text-red-700' : 'text-green-700' }}"><td class="px-3 py-3">{{ $rep['totals']['net'] < 0 ? 'Net loss' : 'Net profit' }}</td><td class="px-3 py-3 text-right">{{ $m($rep['totals']['net']) }}</td></tr>
    </tbody></table></div>
    <p class="text-xs text-gray-500">Discounts/returns "Income"-shown as negative below this. Income and expenses follow the date of your journal entries; a cancelled entry and its reversal net to zero together.</p>
</x-layouts.app>
