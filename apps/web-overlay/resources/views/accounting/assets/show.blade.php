<x-layouts.app title="{{ $a['name'] }}">
    {{-- JARVIS ONE: page-specific visual identity; existing actions preserved --}}
    <header class="jarvis-module-hero jarvis-accent-violet" data-jarvis-module="accounting" aria-label="Accounting details">
      <div class="jarvis-module-hero__identity"><span class="jarvis-module-hero__glyph" aria-hidden="true">✦</span><div>
        <div class="jarvis-module-hero__eyebrow">JARVIS ONE <span aria-hidden="true">/</span> ACCOUNTING</div>
        <h1>Accounting details</h1><p>Review transactions, balances and accounting workflows.</p>
      </div></div>
      <span class="jarvis-module-hero__section">Accounting Workspace</span>
    </header>

    @php($m = fn ($v) => \App\Accounting\AccText::money((int) $v))
    <div class="flex items-center justify-between"><h2 class="text-xl font-semibold">{{ $a['name'] }} <span class="ml-1 rounded bg-gray-100 px-2 py-0.5 text-xs font-normal">{{ $a['status'] === 'disposed' ? 'Sold/Disposed' : 'Active' }}</span></h2><a class="text-sm text-indigo-600" href="{{ route('accounting.assets') }}">← All assets</a></div>
    @include('accounting._nav')
    <section class="grid gap-2 text-sm sm:grid-cols-4">
        <div class="rounded-lg bg-white p-3 shadow"><div class="text-xs text-gray-500">Expenses</div><div class="font-semibold">{{ $m($a['cost_minor']) }}</div></div>
        <div class="rounded-lg bg-white p-3 shadow"><div class="text-xs text-gray-500">Residual value / Life</div><div class="font-semibold">{{ $m($a['salvage_minor']) }} / {{ $a['life_months'] }} month(s)</div></div>
        <div class="rounded-lg bg-white p-3 shadow"><div class="text-xs text-gray-500">Depreciation to date</div><div class="font-semibold">{{ $m($a['accumulated_minor']) }}</div></div>
        <div class="rounded-lg bg-white p-3 shadow"><div class="text-xs text-gray-500">{{ $a['status'] === 'disposed' ? 'Sale proceeds' : 'Current value' }}</div><div class="font-semibold">{{ $a['status'] === 'disposed' ? $m($a['proceeds_minor']) : $m($a['cost_minor'] - $a['accumulated_minor']) }}</div></div>
    </section>
    <div class="overflow-x-auto rounded-lg bg-white shadow"><table class="min-w-full text-sm"><thead class="bg-gray-50 text-left text-xs text-gray-500"><tr><th class="px-3 py-2"> month(s)</th><th class="px-3 py-2 text-right">Depreciation</th><th class="px-3 py-2">Posted?</th></tr></thead><tbody>
        @foreach ($schedule as $s)<tr class="border-t"><td class="px-3 py-1">{{ $s['period'] }}</td><td class="px-3 py-1 text-right">{{ $m($s['amount_minor']) }}</td><td class="px-3 py-1">{{ $s['done'] ? '✔' : '' }}</td></tr>@endforeach</tbody></table></div>
    @if ($a['status'] === 'disposed')
        <p class="rounded bg-gray-50 px-3 py-2 text-sm"><a class="text-indigo-600" href="{{ route('accounting.show', $a['disposal_entry_id']) }}">Sale/disposal journal entry</a> — {{ $a['disposed_date'] }}. No depreciation is charged in the month of sale.</p>
    @elseif ($canManage)
        <form method="POST" action="{{ route('accounting.assets.dispose', $a['id']) }}" class="flex flex-wrap items-end gap-2 rounded-lg bg-white p-5 text-sm shadow">@csrf
            <span class="w-full text-xs text-gray-500">Sale or disposal: depreciation for all earlier months must be posted. The difference between book value and the amount received goes to "Other income" (gain) or "Miscellaneous expense" (loss) account.</span>
            <label>Date<input type="date" name="date" value="{{ old('date', $today) }}" max="{{ $today }}" required class="ml-1 rounded border px-1 py-1"></label>
            <label>Amount received (৳; 0 if disposed)<input name="proceeds" value="{{ old('proceeds', '0') }}" inputmode="decimal" class="ml-1 w-28 rounded border px-1 py-1"></label>
            <label>Account received into<select name="account_id" class="ml-1 rounded border px-1 py-1"><option value="">—</option>@foreach ($cash as $c)<option value="{{ $c['id'] }}">{{ $c['name_bn'] ?: $c['name'] }}</option>@endforeach</select></label>
            <button class="rounded border px-3 py-1 text-red-700">Sell/Dispose</button></form>
    @endif
</x-layouts.app>
