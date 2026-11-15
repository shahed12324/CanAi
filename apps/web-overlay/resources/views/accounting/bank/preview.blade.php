<x-layouts.app title="Preview">
    {{-- JARVIS ONE: page-specific visual identity; existing actions preserved --}}
    <header class="jarvis-module-hero jarvis-accent-violet" data-jarvis-module="accounting" aria-label="Preview">
      <div class="jarvis-module-hero__identity"><span class="jarvis-module-hero__glyph" aria-hidden="true">✦</span><div>
        <div class="jarvis-module-hero__eyebrow">JARVIS ONE <span aria-hidden="true">/</span> ACCOUNTING</div>
        <h1>Preview</h1><p>Review transactions, balances and accounting workflows.</p>
      </div></div>
      <span class="jarvis-module-hero__section">Accounting Workspace</span>
    </header>

    @php($m = fn ($v) => \App\Accounting\AccText::money((int) $v))
    <h2 class="text-xl font-semibold">Statement preview — {{ $a['code'] }} {{ $a['name_bn'] ?: $a['name'] }}</h2>
    @include('accounting._nav')
    @if ($p['error'])<p class="rounded bg-red-50 px-3 py-2 text-sm text-red-800">{{ $p['error'] }}</p>@endif
    @foreach ($p['errors'] as $e)<p class="rounded bg-red-50 px-3 py-1 text-xs text-red-800">{{ $e }}</p>@endforeach
    <form method="POST" action="{{ route('accounting.bank.preview', $a['id']) }}" class="space-y-3 rounded-lg bg-white p-5 text-sm shadow">@csrf<input type="hidden" name="token" value="{{ $token }}">
        <div class="grid gap-2 sm:grid-cols-4">@foreach ([['date', 'Date'], ['description', 'Description'], ['reference', 'Reference/Trx ID'], ['debit', 'Debit/Withdrawal (money out)'], ['credit', 'Credit/Deposit (money in)'], ['amount', 'Amount (single column, negative = out)'], ['balance', 'Balance']] as [$f, $t])
            <label>{{ $t }}<select name="map[{{ $f }}]" class="mt-1 w-full rounded border px-1 py-1"><option value="">— None —</option>@foreach ($p['headers'] as $i => $h)<option value="{{ $i }}" @selected(($p['mapping'][$f] ?? null) === $i)>{{ $i + 1 }}. {{ $h }}</option>@endforeach</select></label>@endforeach</div>
        <label class="block"><input type="checkbox" name="invert" value="1" @checked($invert)> Flip the sign of amounts (if the bank shows withdrawals as positive)</label>
        <button class="rounded border px-3 py-1">Change columns and preview again</button></form>
    @if ($p['rows'])
        <div class="overflow-x-auto rounded-lg bg-white shadow"><table class="min-w-full text-sm"><thead class="bg-gray-50 text-left text-xs text-gray-500"><tr><th class="px-3 py-2">Date</th><th class="px-3 py-2">Description</th><th class="px-3 py-2">Ref</th><th class="px-3 py-2 text-right">Amount (+in / −out)</th><th class="px-3 py-2 text-right">Balance</th></tr></thead><tbody>
            @foreach (array_slice($p['rows'], 0, 20) as $r)<tr class="border-t"><td class="px-3 py-1">{{ $r['date'] }}</td><td class="px-3 py-1">{{ $r['description'] }}</td><td class="px-3 py-1">{{ $r['reference'] }}</td><td class="px-3 py-1 text-right {{ $r['amount_minor'] < 0 ? 'text-red-700' : 'text-green-700' }}">{{ $r['amount_minor'] < 0 ? '−' : '+' }}{{ $m(abs($r['amount_minor'])) }}</td><td class="px-3 py-1 text-right">{{ $r['balance_minor'] === null ? '' : $m($r['balance_minor']) }}</td></tr>@endforeach</tbody></table></div>
        <p class="text-sm text-gray-600">Total {{ count($p['rows']) }} lines found (first 20 shown), {{ $p['skipped'] }} skipped (zero/blank amount or no date).</p>
    @endif
    @if ($p['ok'])
        <form method="POST" action="{{ route('accounting.bank.import', $a['id']) }}" class="text-sm">@csrf<input type="hidden" name="token" value="{{ $token }}">@if ($invert)<input type="hidden" name="invert" value="1">@endif
            @foreach ($p['mapping'] as $f => $i)<input type="hidden" name="map[{{ $f }}]" value="{{ $i }}">@endforeach
            <button class="rounded bg-indigo-600 px-4 py-2 text-white">{{ count($p['rows']) }} Import lines</button> <a class="ml-2 text-gray-500" href="{{ route('accounting.bank.show', $a['id']) }}">Cancel</a></form>
    @endif
</x-layouts.app>
