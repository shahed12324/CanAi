<x-layouts.app title="Entry">
    {{-- JARVIS ONE: page-specific visual identity; existing actions preserved --}}
    <header class="jarvis-module-hero jarvis-accent-violet" data-jarvis-module="accounting" aria-label="Entry">
      <div class="jarvis-module-hero__identity"><span class="jarvis-module-hero__glyph" aria-hidden="true">✦</span><div>
        <div class="jarvis-module-hero__eyebrow">JARVIS ONE <span aria-hidden="true">/</span> ACCOUNTING</div>
        <h1>Entry</h1><p>Review transactions, balances and accounting workflows.</p>
      </div></div>
      <span class="jarvis-module-hero__section">Accounting Workspace</span>
    </header>

    @php($e = $entry)
    <div class="flex items-center justify-between"><h2 class="text-xl font-semibold">Entry #{{ $e['number'] }}</h2><a class="text-sm text-indigo-600" href="{{ route('accounting.index') }}">← Journal</a></div>
    @include('accounting._nav')
    <section class="rounded-lg bg-white p-5 text-sm shadow"><p><b>{{ $e['memo'] }}</b> · {{ $e['entry_date'] }}</p>
        @if ($e['source'] === 'reversal')<p class="text-gray-600">This is entry no. {{ $e['source_ref'] }}-cancellation entry.</p>@endif
        @if ($reversed_by)<p class="text-amber-700">This entry has been cancelled → <a class="underline" href="{{ route('accounting.show', $reversed_by['id']) }}">#{{ $reversed_by['number'] }}</a></p>@endif</section>
    <div class="overflow-x-auto rounded-lg bg-white shadow"><table class="min-w-full text-sm"><thead class="bg-gray-50 text-left text-xs text-gray-500"><tr><th class="px-3 py-2">Account</th><th class="px-3 py-2 text-right">Debit</th><th class="px-3 py-2 text-right">Credits</th></tr></thead><tbody>
        @foreach ($lines as $l)<tr class="border-t"><td class="px-3 py-2">{{ $l['code'] }} {{ $l['name_bn'] ?: $l['name'] }}@if ($l['memo']) <span class="text-xs text-gray-500">— {{ $l['memo'] }}</span>@endif</td><td class="px-3 py-2 text-right">{{ $l['debit_minor'] > 0 ? \App\Accounting\AccText::money((int) $l['debit_minor']) : '' }}</td><td class="px-3 py-2 text-right">{{ $l['credit_minor'] > 0 ? \App\Accounting\AccText::money((int) $l['credit_minor']) : '' }}</td></tr>@endforeach
        <tr class="border-t font-semibold"><td class="px-3 py-2">Total</td><td class="px-3 py-2 text-right">{{ \App\Accounting\AccText::money((int) array_sum(array_column($lines, 'debit_minor'))) }}</td><td class="px-3 py-2 text-right">{{ \App\Accounting\AccText::money((int) array_sum(array_column($lines, 'credit_minor'))) }}</td></tr></tbody></table></div>
    @if ($canManage && $e['source'] !== 'reversal' && ! $reversed_by)
        <form method="POST" action="{{ route('accounting.reverse', $e['id']) }}" class="flex flex-wrap items-center gap-2 rounded-lg bg-white p-5 text-sm shadow">@csrf<input type="date" name="entry_date" value="{{ $today }}" max="{{ $today }}" required class="rounded border px-2 py-1"><input name="memo" maxlength="300" placeholder="Reason (optional)" class="rounded border px-2 py-1"><button class="rounded border px-3 py-1">Cancel (reverse)</button></form>
    @endif
</x-layouts.app>
