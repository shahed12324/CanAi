<x-layouts.app title="Analysis">
    {{-- JARVIS ONE: page-specific visual identity; existing actions preserved --}}
    <header class="jarvis-module-hero jarvis-accent-violet" data-jarvis-module="accounting" aria-label="Analysis">
      <div class="jarvis-module-hero__identity"><span class="jarvis-module-hero__glyph" aria-hidden="true">✦</span><div>
        <div class="jarvis-module-hero__eyebrow">JARVIS ONE <span aria-hidden="true">/</span> ACCOUNTING</div>
        <h1>Analysis</h1><p>Review transactions, balances and accounting workflows.</p>
      </div></div>
      <span class="jarvis-module-hero__section">Accounting Workspace</span>
    </header>

    @php($m = fn ($v) => \App\Accounting\AccText::money((int) $v))
    @php($f = $r['facts'])
    @php($fc = $r['forecast'])
    <h2 class="text-xl font-semibold">{{ \App\Payroll\PayrollText::month($r['month_to']) }} Analysis up to</h2>
    @include('accounting._nav')
    <p class="text-sm"><a class="text-indigo-600" href="{{ route('accounting.analyst') }}">← All analyses</a></p>
    <section class="rounded-lg bg-white p-4 text-sm shadow">
        <p class="mb-2 text-xs {{ $r['source'] === 'ai' ? 'text-indigo-700' : 'text-gray-500' }}">@if ($r['source'] === 'ai')AI-Written explanation ({{ $r['model'] }}) — Every number was checked against the books. Cost: {{ $r['credits_charged'] }} credits.@else Fixed summary (no AI)@if ($note) — Reason: {{ $note }}@endif @if ((float) $r['credits_charged'] > 0). For AI, {{ $r['credits_charged'] }} credits were used.@endif @endif</p>
        @foreach (explode("\n", $r['narrative']) as $line)<p class="mt-1">{{ $line }}</p>@endforeach</section>

    <section class="overflow-x-auto rounded-lg bg-white shadow"><p class="px-3 pt-3 text-sm font-semibold">Monthly (from the books)</p>
        <table class="min-w-full text-sm"><thead class="bg-gray-50 text-left text-xs text-gray-500"><tr><th class="px-3 py-2"> month(s)</th><th class="px-3 py-2 text-right">Income</th><th class="px-3 py-2 text-right">Expenses</th><th class="px-3 py-2 text-right">Profit/Loss</th><th class="px-3 py-2 text-right">Cash flow</th></tr></thead><tbody>
        @foreach ($f['months'] as $x)<tr class="border-t"><td class="px-3 py-1">{{ \App\Payroll\PayrollText::month($x['ym']) }}</td><td class="px-3 py-1 text-right">{{ $m($x['income_minor']) }}</td><td class="px-3 py-1 text-right">{{ $m($x['expense_minor']) }}</td><td class="px-3 py-1 text-right {{ $x['profit_minor'] < 0 ? 'text-red-700' : '' }}">{{ $m($x['profit_minor']) }}</td><td class="px-3 py-1 text-right">{{ $m($x['cash_net_minor']) }}</td></tr>@endforeach</tbody></table></section>

    <section class="overflow-x-auto rounded-lg bg-amber-50 shadow"><p class="px-3 pt-3 text-sm font-semibold text-amber-900">Estimated forecast — not certain</p>
        @if ($fc['enough'])
        <p class="px-3 text-xs text-amber-800">Method: {{ $fc['method'] }}. "Limit" The likely range based on past ups and downs — not a guarantee.</p>
        <table class="min-w-full text-sm"><thead class="text-left text-xs text-amber-900"><tr><th class="px-3 py-2"> month(s)</th><th class="px-3 py-2 text-right">Income (est.)</th><th class="px-3 py-2 text-right">Expenses (est.)</th><th class="px-3 py-2 text-right">Profit/Loss (est.)</th><th class="px-3 py-2 text-right">Profit range</th><th class="px-3 py-2 text-right">Cash at month end (est.)</th></tr></thead><tbody>
        @foreach ($fc['months'] as $x)<tr class="border-t border-amber-200"><td class="px-3 py-1">{{ \App\Payroll\PayrollText::month($x['ym']) }}</td><td class="px-3 py-1 text-right">{{ $m($x['income_minor']) }}</td><td class="px-3 py-1 text-right">{{ $m($x['expense_minor']) }}</td><td class="px-3 py-1 text-right">{{ $m($x['profit_minor']) }}</td><td class="px-3 py-1 text-right text-xs">{{ $m($x['profit_low_minor']) }} … {{ $m($x['profit_high_minor']) }}</td><td class="px-3 py-1 text-right">{{ $m($x['cash_end_minor']) }}</td></tr>@endforeach</tbody></table>
        @if ($fc['runway_months'] !== null)<p class="px-3 py-2 text-sm text-red-800">If cash keeps falling at this rate, the cash in hand will last about {{ $fc['runway_months'] }} months.</p>@endif
        @else<p class="px-3 py-3 text-sm text-amber-900">{{ $fc['reason'] }}</p>@endif</section>
</x-layouts.app>
