<x-layouts.app title="Trial balance">
    {{-- JARVIS ONE: page-specific visual identity; existing actions preserved --}}
    <header class="jarvis-module-hero jarvis-accent-violet" data-jarvis-module="accounting" aria-label="Trial balance">
      <div class="jarvis-module-hero__identity"><span class="jarvis-module-hero__glyph" aria-hidden="true">✦</span><div>
        <div class="jarvis-module-hero__eyebrow">JARVIS ONE <span aria-hidden="true">/</span> ACCOUNTING</div>
        <h1>Trial balance</h1><p>Review transactions, balances and accounting workflows.</p>
      </div></div>
      <span class="jarvis-module-hero__section">Accounting Workspace</span>
    </header>

    <h2 class="text-xl font-semibold">Trial balance</h2>
    @include('accounting._nav')
    <form method="GET" class="flex items-end gap-2 text-sm"><label>As of date<input type="date" name="asof" value="{{ $asof }}" class="ml-2 rounded border px-2 py-1"></label><button class="rounded bg-indigo-600 px-3 py-1 text-white">View</button></form>
    <div class="overflow-x-auto rounded-lg bg-white shadow"><table class="min-w-full text-sm"><thead class="bg-gray-50 text-left text-xs text-gray-500"><tr><th class="px-3 py-2">Code</th><th class="px-3 py-2">Account</th><th class="px-3 py-2 text-right">Debit</th><th class="px-3 py-2 text-right">Credits</th></tr></thead><tbody>
        @foreach ($rows as $r)<tr class="border-t"><td class="px-3 py-1">{{ $r['code'] }}</td><td class="px-3 py-1">{{ $r['name_bn'] ?: $r['name'] }}</td><td class="px-3 py-1 text-right">{{ $r['debit_minor'] > 0 ? \App\Accounting\AccText::money((int) $r['debit_minor']) : '' }}</td><td class="px-3 py-1 text-right">{{ $r['credit_minor'] > 0 ? \App\Accounting\AccText::money((int) $r['credit_minor']) : '' }}</td></tr>@endforeach
        <tr class="border-t font-semibold"><td colspan="2" class="px-3 py-2">Total {!! $debit === $credit ? '<span class="text-green-700">✔ Matches</span>' : '<span class="text-red-700">✘ Does not match</span>' !!}</td><td class="px-3 py-2 text-right">{{ \App\Accounting\AccText::money((int) $debit) }}</td><td class="px-3 py-2 text-right">{{ \App\Accounting\AccText::money((int) $credit) }}</td></tr></tbody></table></div>
</x-layouts.app>
