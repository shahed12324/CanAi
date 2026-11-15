<x-layouts.app title="New entry">
    {{-- JARVIS ONE: page-specific visual identity; existing actions preserved --}}
    <header class="jarvis-module-hero jarvis-accent-violet" data-jarvis-module="accounting" aria-label="New entry">
      <div class="jarvis-module-hero__identity"><span class="jarvis-module-hero__glyph" aria-hidden="true">✦</span><div>
        <div class="jarvis-module-hero__eyebrow">JARVIS ONE <span aria-hidden="true">/</span> ACCOUNTING</div>
        <h1>New entry</h1><p>Review transactions, balances and accounting workflows.</p>
      </div></div>
      <span class="jarvis-module-hero__section">Accounting Workspace</span>
    </header>

    <h2 class="text-xl font-semibold">New journal entry</h2>
    @include('accounting._nav')
    <form method="POST" action="{{ route('accounting.store') }}" class="space-y-3 rounded-lg bg-white p-5 text-sm shadow">@csrf
        <div class="grid gap-2 sm:grid-cols-3"><label>Date<input type="date" name="entry_date" value="{{ old('entry_date', $today) }}" max="{{ $today }}" required class="mt-1 w-full rounded border px-2 py-1"></label>
            <label class="sm:col-span-2">Description<input name="memo" value="{{ old('memo') }}" maxlength="300" required class="mt-1 w-full rounded border px-2 py-1"></label></div>
        <table class="min-w-full"><thead class="text-left text-xs text-gray-500"><tr><th>Account</th><th>Debit (৳)</th><th>Credit (৳)</th><th>Note</th></tr></thead><tbody>
            @for ($i = 0; $i < 6; $i++)<tr><td class="pr-2"><select name="rows[{{ $i }}][account_id]" class="w-full rounded border px-1 py-1"><option value="">—</option>@foreach ($accounts as $a)<option value="{{ $a['id'] }}" @selected(old("rows.$i.account_id") === $a['id'])>{{ $a['code'] }} {{ $a['name_bn'] ?: $a['name'] }}</option>@endforeach</select></td>
                <td class="pr-2"><input name="rows[{{ $i }}][debit]" value="{{ old("rows.$i.debit") }}" inputmode="decimal" class="w-28 rounded border px-1 py-1"></td><td class="pr-2"><input name="rows[{{ $i }}][credit]" value="{{ old("rows.$i.credit") }}" inputmode="decimal" class="w-28 rounded border px-1 py-1"></td>
                <td><input name="rows[{{ $i }}][memo]" value="{{ old("rows.$i.memo") }}" maxlength="200" class="w-full rounded border px-1 py-1"></td></tr>@endfor</tbody></table>
        <p class="text-xs text-gray-500">Debit or credit on each line. It will not save unless total debit = total credit. An entry cannot be deleted if wrong — "reverse" it and write a new one.</p>
        <button class="rounded bg-indigo-600 px-3 py-1 text-white">Create entry</button>
    </form>
</x-layouts.app>
