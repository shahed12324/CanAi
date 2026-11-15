<x-layouts.app title="Inventory valuation">
    {{-- JARVIS ONE: page-specific visual identity; existing actions preserved --}}
    <header class="jarvis-module-hero jarvis-accent-violet" data-jarvis-module="accounting" aria-label="Inventory valuation">
      <div class="jarvis-module-hero__identity"><span class="jarvis-module-hero__glyph" aria-hidden="true">✦</span><div>
        <div class="jarvis-module-hero__eyebrow">JARVIS ONE <span aria-hidden="true">/</span> ACCOUNTING</div>
        <h1>Inventory valuation</h1><p>Review transactions, balances and accounting workflows.</p>
      </div></div>
      <span class="jarvis-module-hero__section">Accounting Workspace</span>
    </header>

    @php($m = fn ($v) => \App\Accounting\AccText::money((int) $v))
    <h2 class="text-xl font-semibold">Inventory valuation <span class="text-sm font-normal text-gray-500">{{ $asof }} As of</span></h2>
    @include('accounting._nav')
    <form method="GET" class="flex items-end gap-2 text-sm"><label>Date <input type="date" name="asof" value="{{ $asof }}" max="{{ $today }}" class="rounded border px-1 py-1"></label><button class="rounded bg-indigo-600 px-3 py-1 text-white">View</button></form>
    <section class="grid gap-2 text-sm sm:grid-cols-3">
        <div class="rounded-lg bg-white p-3 shadow"><div class="text-xs text-gray-500">Stock value (quantity × cost)</div><div class="font-semibold">{{ $m($value) }}</div></div>
        <div class="rounded-lg bg-white p-3 shadow"><div class="text-xs text-gray-500">In the ledger "Inventory"</div><div class="font-semibold">{{ $m($book) }}</div></div>
        <div class="rounded-lg bg-white p-3 shadow {{ $value === $book ? 'bg-green-50' : 'bg-amber-50' }}"><div class="text-xs text-gray-500">Difference</div><div class="font-semibold">{{ $value === $book ? '✔ Matches' : $m($value - $book) }}</div></div>
    </section>
    @if ($missing > 0)<p class="rounded bg-amber-50 px-3 py-2 text-sm text-amber-900">{{ $missing }} items have no cost set — they are counted as 0. Enter the costs below.</p>@endif
    @if ($canManage && $value !== $book)
        <form method="POST" action="{{ route('accounting.inventory.revalue') }}" class="flex flex-wrap items-end gap-2 rounded-lg bg-white p-4 text-sm shadow">@csrf<input type="hidden" name="date" value="{{ $asof }}">
            <span class="w-full text-xs text-gray-500">Match the ledger inventory account to the stock value; the difference goes to the income/expense account you choose (usually "Cost of goods sold") ). {{ $asof }} A journal entry will be made on this date.</span>
            <label>Offset account<select name="counter" class="ml-1 rounded border px-1 py-1">@foreach ($counters as $c)<option value="{{ $c['id'] }}" @selected($c['system_key'] === 'cogs')>{{ $c['code'] }} {{ $c['name_bn'] ?: $c['name'] }}</option>@endforeach</select></label>
            <button class="rounded bg-indigo-600 px-3 py-1 text-white">Adjust the ledger</button></form>
    @endif
    <form method="POST" action="{{ route('accounting.inventory.cost') }}">@csrf
        <div class="overflow-x-auto rounded-lg bg-white shadow"><table class="min-w-full text-sm"><thead class="bg-gray-50 text-left text-xs text-gray-500"><tr><th class="px-3 py-2">Product</th><th class="px-3 py-2 text-right">Quantity</th><th class="px-3 py-2 text-right">Unit cost</th><th class="px-3 py-2 text-right">Value</th>@if ($canManage)<th class="px-3 py-2">New cost (৳)</th>@endif</tr></thead><tbody>
            @forelse ($rows as $r)<tr class="border-t"><td class="px-3 py-1">{{ $r['title'] }} @if ($r['sku'])<span class="text-xs text-gray-500">{{ $r['sku'] }}</span>@endif @php($o = json_decode((string) $r['options'], true))@if ($o)<span class="text-xs text-gray-500">{{ implode(', ', $o) }}</span>@endif</td><td class="px-3 py-1 text-right">{{ $r['qty'] }}</td><td class="px-3 py-1 text-right">{{ $r['unit_cost_minor'] === null ? '—' : $m($r['unit_cost_minor']) }}</td><td class="px-3 py-1 text-right">{{ $m($r['value_minor']) }}</td>
                @if ($canManage)<td class="px-3 py-1"><input name="costs[{{ $r['variant_id'] }}]" inputmode="decimal" class="w-24 rounded border px-1 py-0.5"></td>@endif</tr>
            @empty<tr><td colspan="5" class="px-3 py-6 text-center text-gray-500">There is no stock on this date.</td></tr>@endforelse</tbody></table></div>
        @if ($canManage && $rows)<div class="mt-2 flex items-center gap-2 text-sm"><label>Effective date <input type="date" name="date" value="{{ $today }}" max="{{ $today }}" class="rounded border px-1 py-1"></label><button class="rounded border px-3 py-1">Save costs</button><span class="text-xs text-gray-500">Leave blank to keep it unchanged; cost history is kept, and earlier dates are not changed.</span></div>@endif
    </form>
    <p class="text-xs text-gray-500">Stock quantity comes from the stock-movement ledger (as at the end of the date); products with stock tracking off are not counted.</p>
</x-layouts.app>
