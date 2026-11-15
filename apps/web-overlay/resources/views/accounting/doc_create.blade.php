<x-layouts.app title="New {{ \App\Accounting\AccText::kindBn($kind) }}">
    {{-- JARVIS ONE: page-specific visual identity; existing actions preserved --}}
    <header class="jarvis-module-hero jarvis-accent-violet" data-jarvis-module="accounting" aria-label="Accounting — Doc Create">
      <div class="jarvis-module-hero__identity"><span class="jarvis-module-hero__glyph" aria-hidden="true">✦</span><div>
        <div class="jarvis-module-hero__eyebrow">JARVIS ONE <span aria-hidden="true">/</span> ACCOUNTING</div>
        <h1>Accounting — Doc Create</h1><p>Review transactions, balances and accounting workflows.</p>
      </div></div>
      <span class="jarvis-module-hero__section">Accounting Workspace</span>
    </header>

    <h2 class="text-xl font-semibold">New {{ \App\Accounting\AccText::kindBn($kind) }}</h2>
    @include('accounting._nav')
    <form method="POST" action="{{ route('accounting.docs.store', $plural) }}" class="space-y-3 rounded-lg bg-white p-5 text-sm shadow">@csrf
        <div class="grid gap-2 sm:grid-cols-4">
            @if ($kind !== 'expense' || $parties)<label class="sm:col-span-2">{{ $kind === 'invoice' ? 'Customer' : 'Supplier' }}{{ $kind === 'expense' ? ' (optional)' : '' }}<select name="party_id" @if ($kind !== 'expense') required @endif class="mt-1 w-full rounded border px-2 py-1"><option value="">—</option>@foreach ($parties as $p)@if ($p['active'])<option value="{{ $p['id'] }}" @selected(old('party_id') === $p['id'])>{{ $p['name'] }}</option>@endif @endforeach</select></label>@endif
            <label>Date<input type="date" name="issue_date" value="{{ old('issue_date', $today) }}" max="{{ $today }}" required class="mt-1 w-full rounded border px-2 py-1"></label>
            @if ($kind !== 'expense')<label>Due date<input type="date" name="due_date" value="{{ old('due_date') }}" class="mt-1 w-full rounded border px-2 py-1"></label>@endif
            @if ($kind === 'expense')<label class="sm:col-span-2">Where the money came from<select name="pay_account" required class="mt-1 w-full rounded border px-2 py-1">@foreach ($cash as $c)<option value="{{ $c['account_id'] }}">{{ $c['code'] }} {{ $c['name_bn'] ?: $c['name'] }}</option>@endforeach</select></label>@endif
            <label>Reference<input name="reference" value="{{ old('reference') }}" maxlength="60" class="mt-1 w-full rounded border px-2 py-1"></label>
        </div>
        <table class="min-w-full"><thead class="text-left text-xs text-gray-500"><tr><th>Account</th><th>Description</th><th>Amount (৳)</th></tr></thead><tbody>
            @for ($i = 0; $i < 5; $i++)<tr><td class="pr-2"><select name="rows[{{ $i }}][account_id]" class="w-full rounded border px-1 py-1"><option value="">—</option>@foreach ($lineAccounts as $a)<option value="{{ $a['id'] }}" @selected(old("rows.$i.account_id") === $a['id'])>{{ $a['code'] }} {{ $a['name_bn'] ?: $a['name'] }}</option>@endforeach</select></td>
                <td class="pr-2"><input name="rows[{{ $i }}][description]" value="{{ old("rows.$i.description") }}" maxlength="200" class="w-full rounded border px-1 py-1"></td><td><input name="rows[{{ $i }}][amount]" value="{{ old("rows.$i.amount") }}" inputmode="decimal" class="w-32 rounded border px-1 py-1"></td></tr>@endfor</tbody></table>
        <label class="block">VAT/tax (৳, calculate yourself; optional)<input name="vat" value="{{ old('vat') }}" inputmode="decimal" class="ml-2 w-32 rounded border px-1 py-1"></label>
        <button class="rounded bg-indigo-600 px-3 py-1 text-white">Save</button>
    </form>
</x-layouts.app>
