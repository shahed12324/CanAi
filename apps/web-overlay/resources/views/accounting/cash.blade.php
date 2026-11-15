<x-layouts.app title="Cash-Bank">
    {{-- JARVIS ONE: page-specific visual identity; existing actions preserved --}}
    <header class="jarvis-module-hero jarvis-accent-violet" data-jarvis-module="accounting" aria-label="Cash-Bank">
      <div class="jarvis-module-hero__identity"><span class="jarvis-module-hero__glyph" aria-hidden="true">✦</span><div>
        <div class="jarvis-module-hero__eyebrow">JARVIS ONE <span aria-hidden="true">/</span> ACCOUNTING</div>
        <h1>Cash-Bank</h1><p>Review transactions, balances and accounting workflows.</p>
      </div></div>
      <span class="jarvis-module-hero__section">Accounting Workspace</span>
    </header>

    <h2 class="text-xl font-semibold">Cash and bank book</h2>
    @include('accounting._nav')
    <div class="overflow-x-auto rounded-lg bg-white shadow"><table class="min-w-full text-sm"><thead class="bg-gray-50 text-left text-xs text-gray-500"><tr><th class="px-3 py-2">Account</th><th class="px-3 py-2 text-right">Balance today</th><th class="px-3 py-2"></th></tr></thead><tbody>
        @foreach ($rows as $r)<tr class="border-t"><td class="px-3 py-2">{{ $r['code'] }} {{ $r['name_bn'] ?: $r['name'] }}</td><td class="px-3 py-2 text-right {{ $r['balance_minor'] < 0 ? 'text-red-700' : '' }}">{{ \App\Accounting\AccText::money((int) $r['balance_minor']) }}</td><td class="px-3 py-2"><a class="text-indigo-600" href="{{ route('accounting.ledger', ['account' => $r['account_id']]) }}">Book (ledger)</a></td></tr>@endforeach
        <tr class="border-t font-semibold"><td class="px-3 py-2">Total</td><td class="px-3 py-2 text-right">{{ \App\Accounting\AccText::money((int) array_sum(array_column($rows, 'balance_minor'))) }}</td><td></td></tr></tbody></table></div>
    @if ($canSettings)<form method="POST" action="{{ route('accounting.cash.mark') }}" class="flex flex-wrap items-center gap-2 rounded-lg bg-white p-5 text-sm shadow">@csrf<span>Mark an account you added as cash/bank:</span>
        <select name="account_id" class="rounded border px-2 py-1">@foreach ($candidates as $a)<option value="{{ $a['id'] }}">{{ $a['code'] }} {{ $a['name_bn'] ?: $a['name'] }}</option>@endforeach</select><select name="on" class="rounded border px-2 py-1"><option value="1">Mark</option><option value="0">Unmark</option></select><button class="rounded border px-3 py-1">Save</button></form>@endif
</x-layouts.app>
