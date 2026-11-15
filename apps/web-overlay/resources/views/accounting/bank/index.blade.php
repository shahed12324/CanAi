<x-layouts.app title="Statement matching">
    {{-- JARVIS ONE: page-specific visual identity; existing actions preserved --}}
    <header class="jarvis-module-hero jarvis-accent-violet" data-jarvis-module="accounting" aria-label="Statement matching">
      <div class="jarvis-module-hero__identity"><span class="jarvis-module-hero__glyph" aria-hidden="true">✦</span><div>
        <div class="jarvis-module-hero__eyebrow">JARVIS ONE <span aria-hidden="true">/</span> ACCOUNTING</div>
        <h1>Statement matching</h1><p>Review transactions, balances and accounting workflows.</p>
      </div></div>
      <span class="jarvis-module-hero__section">Accounting Workspace</span>
    </header>

    @php($m = fn ($v) => \App\Accounting\AccText::money((int) $v))
    <h2 class="text-xl font-semibold">Match bank / bKash / Nagad statements</h2>
    @include('accounting._nav')
    <div class="overflow-x-auto rounded-lg bg-white shadow"><table class="min-w-full text-sm"><thead class="bg-gray-50 text-left text-xs text-gray-500"><tr><th class="px-3 py-2">Account</th><th class="px-3 py-2 text-right">Balance in the books</th><th class="px-3 py-2 text-right">Statement balance</th><th class="px-3 py-2 text-right">Unmatched lines</th><th class="px-3 py-2"></th></tr></thead><tbody>
        @forelse ($rows as $r)<tr class="border-t"><td class="px-3 py-2">{{ $r['code'] }} {{ $r['name_bn'] ?: $r['name'] }}</td><td class="px-3 py-2 text-right">{{ $m($r['rec']['book_minor']) }}</td><td class="px-3 py-2 text-right">{{ $r['rec']['statement_minor'] === null ? '—' : $m($r['rec']['statement_minor']) }}</td><td class="px-3 py-2 text-right">{{ $r['rec']['open_lines'] }}</td><td class="px-3 py-2"><a class="text-indigo-600" href="{{ route('accounting.bank.show', $r['id']) }}">Open</a></td></tr>
        @empty<tr><td colspan="5" class="px-3 py-6 text-center text-gray-500">No cash/bank account — "Cash-Bank" mark one on the page.</td></tr>@endforelse</tbody></table></div>
</x-layouts.app>
