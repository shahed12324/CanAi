<x-layouts.app title="Cash flow">
    {{-- JARVIS ONE: page-specific visual identity; existing actions preserved --}}
    <header class="jarvis-module-hero jarvis-accent-violet" data-jarvis-module="accounting" aria-label="Cash flow">
      <div class="jarvis-module-hero__identity"><span class="jarvis-module-hero__glyph" aria-hidden="true">✦</span><div>
        <div class="jarvis-module-hero__eyebrow">JARVIS ONE <span aria-hidden="true">/</span> ACCOUNTING</div>
        <h1>Cash flow</h1><p>Review transactions, balances and accounting workflows.</p>
      </div></div>
      <span class="jarvis-module-hero__section">Accounting Workspace</span>
    </header>

    @php($m = fn ($v) => \App\Accounting\AccText::money((int) $v))
    <h2 class="text-xl font-semibold">Cash flow <span class="text-sm font-normal text-gray-500">{{ $from }} from {{ $to }}</span></h2>
    @include('accounting._nav')
    @include('accounting.reports._period')
    <p class="rounded px-3 py-2 text-sm {{ $rep['reconciled'] ? 'bg-green-50 text-green-800' : 'bg-red-50 text-red-800' }}">{{ $rep['reconciled'] ? '✔ Balanced: opening cash + net flow = closing cash' : '✘ Not balanced — this should not happen, tell support' }}</p>
    <div class="overflow-x-auto rounded-lg bg-white shadow"><table class="min-w-full text-sm"><tbody>
        <tr class="font-semibold"><td class="px-3 py-2">Opening cash and bank</td><td class="px-3 py-2 text-right">{{ $m($rep['opening']) }}</td></tr>
        @foreach ([['operating', 'From operations'], ['investing', 'Investing (buying/selling assets)'], ['financing', 'Financing (capital, loans, drawings)']] as [$k, $title])
            <tr class="bg-gray-50"><th colspan="2" class="px-3 py-2 text-left">{{ $title }}</th></tr>
            @forelse ($rep[$k] as $r)<tr class="border-t"><td class="px-3 py-1">{{ $r['code'] }} {{ $r['name_bn'] ?: $r['name'] }}</td><td class="px-3 py-1 text-right {{ $r['amount_minor'] < 0 ? 'text-red-700' : '' }}">{{ $m($r['amount_minor']) }}</td></tr>@empty<tr class="border-t"><td colspan="2" class="px-3 py-1 text-gray-400">None</td></tr>@endforelse
            <tr class="border-t font-semibold"><td class="px-3 py-1">Total</td><td class="px-3 py-1 text-right">{{ $m($rep['totals'][$k]) }}</td></tr>
        @endforeach
        <tr class="border-t-2 font-semibold"><td class="px-3 py-2">Net cash flow</td><td class="px-3 py-2 text-right">{{ $m($rep['totals']['net']) }}</td></tr>
        <tr class="font-bold"><td class="px-3 py-2">Closing cash and bank</td><td class="px-3 py-2 text-right">{{ $m($rep['closing']) }}</td></tr>
    </tbody></table></div>
    <p class="text-xs text-gray-500">Each cash transaction is split by its counter account (for example sales income → operations, equipment purchase → investing, capital/loans → financing). Cash↔bank transfers are not counted. "cash" means the accounts marked in the cash-bank book.</p>
</x-layouts.app>
