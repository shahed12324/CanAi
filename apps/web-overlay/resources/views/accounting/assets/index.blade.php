<x-layouts.app title="Fixed assets">
    {{-- JARVIS ONE: page-specific visual identity; existing actions preserved --}}
    <header class="jarvis-module-hero jarvis-accent-violet" data-jarvis-module="accounting" aria-label="Fixed assets">
      <div class="jarvis-module-hero__identity"><span class="jarvis-module-hero__glyph" aria-hidden="true">✦</span><div>
        <div class="jarvis-module-hero__eyebrow">JARVIS ONE <span aria-hidden="true">/</span> ACCOUNTING</div>
        <h1>Fixed assets</h1><p>Review transactions, balances and accounting workflows.</p>
      </div></div>
      <span class="jarvis-module-hero__section">Accounting Workspace</span>
    </header>

    @php($m = fn ($v) => \App\Accounting\AccText::money((int) $v))
    <h2 class="text-xl font-semibold">Fixed assets and depreciation</h2>
    @include('accounting._nav')
    @if ($pending)
        <section class="rounded-lg bg-amber-50 p-4 text-sm text-amber-900 shadow"><p class="font-semibold">Depreciation remaining: {{ count($pending) }} month(s)</p>
            <p class="text-xs">@foreach ($pending as $p){{ $p['period'] }} ({{ $m($p['total_minor']) }} ৳, {{ $p['assets'] }} assets)@if (! $loop->last) · @endif @endforeach</p>
            @if ($canManage)<form method="POST" action="{{ route('accounting.assets.run') }}" class="mt-2 flex items-center gap-2">@csrf<input type="hidden" name="through" value="{{ $today }}"><button class="rounded bg-indigo-600 px-3 py-1 text-white">Post depreciation for all remaining months (one journal entry per month)</button></form>@endif</section>
    @endif
    <div class="overflow-x-auto rounded-lg bg-white shadow"><table class="min-w-full text-sm"><thead class="bg-gray-50 text-left text-xs text-gray-500"><tr><th class="px-3 py-2">Asset</th><th class="px-3 py-2">Purchased</th><th class="px-3 py-2 text-right">Expenses</th><th class="px-3 py-2 text-right">Depreciation (to date)</th><th class="px-3 py-2 text-right">Current value</th><th class="px-3 py-2">Status</th></tr></thead><tbody>
        @forelse ($assets as $a)<tr class="border-t"><td class="px-3 py-2"><a class="text-indigo-600" href="{{ route('accounting.assets.show', $a['id']) }}">{{ $a['name'] }}</a> <span class="text-xs text-gray-500">{{ $a['account_code'] }}</span></td><td class="px-3 py-2">{{ $a['purchase_date'] }}</td><td class="px-3 py-2 text-right">{{ $m($a['cost_minor']) }}</td><td class="px-3 py-2 text-right">{{ $m($a['accumulated_minor']) }}</td><td class="px-3 py-2 text-right">{{ $a['status'] === 'disposed' ? '—' : $m($a['cost_minor'] - $a['accumulated_minor']) }}</td><td class="px-3 py-2">{{ $a['status'] === 'disposed' ? 'Sold/Disposed '.$a['disposed_date'] : 'Active' }}</td></tr>
        @empty<tr><td colspan="6" class="px-3 py-6 text-center text-gray-500">No assets registered.</td></tr>@endforelse</tbody></table></div>
    @if ($canManage)
        <form method="POST" action="{{ route('accounting.assets.store') }}" class="grid gap-2 rounded-lg bg-white p-5 text-sm shadow sm:grid-cols-3">@csrf
            <p class="text-xs text-gray-500 sm:col-span-3">First record the asset purchase (bill/expense/journal, in a fixed-asset account); here you only register it for depreciation. Depreciation is straight-line from the month of purchase, once per month.</p>
            <label>Name<input name="name" required maxlength="120" value="{{ old('name') }}" class="mt-1 w-full rounded border px-2 py-1"></label>
            <label>Account<select name="account_id" class="mt-1 w-full rounded border px-2 py-1">@foreach ($accounts as $a)<option value="{{ $a['id'] }}" @selected(old('account_id') === $a['id'])>{{ $a['code'] }} {{ $a['name_bn'] ?: $a['name'] }}</option>@endforeach</select></label>
            <label>Purchase date<input type="date" name="date" max="{{ $today }}" required value="{{ old('date', $today) }}" class="mt-1 w-full rounded border px-2 py-1"></label>
            <label>Cost (৳)<input name="cost" required inputmode="decimal" value="{{ old('cost') }}" class="mt-1 w-full rounded border px-2 py-1"></label>
            <label>Residual value (৳, optional)<input name="salvage" inputmode="decimal" value="{{ old('salvage') }}" class="mt-1 w-full rounded border px-2 py-1"></label>
            <label>Life (months)<input type="number" name="life" min="1" max="600" required value="{{ old('life', 60) }}" class="mt-1 w-full rounded border px-2 py-1"></label>
            <button class="rounded bg-indigo-600 px-3 py-1 text-white sm:col-span-3">Register</button></form>
    @endif
</x-layouts.app>
