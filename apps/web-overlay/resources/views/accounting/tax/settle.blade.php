<x-layouts.app title="Pay with tax deducted">
    {{-- JARVIS ONE: page-specific visual identity; existing actions preserved --}}
    <header class="jarvis-module-hero jarvis-accent-violet" data-jarvis-module="accounting" aria-label="Pay with tax deducted">
      <div class="jarvis-module-hero__identity"><span class="jarvis-module-hero__glyph" aria-hidden="true">✦</span><div>
        <div class="jarvis-module-hero__eyebrow">JARVIS ONE <span aria-hidden="true">/</span> ACCOUNTING</div>
        <h1>Pay with tax deducted</h1><p>Review transactions, balances and accounting workflows.</p>
      </div></div>
      <span class="jarvis-module-hero__section">Accounting Workspace</span>
    </header>

    @php($m = fn ($v) => \App\Accounting\AccText::money((int) $v))
    @php($doc = $d['doc'])
    @php($bill = $doc['kind'] === 'bill')
    <h2 class="text-xl font-semibold">{{ $bill ? 'Bill payment' : 'Receiving invoice payment' }} — Pay with tax deducted ({{ $doc['number'] }})</h2>
    @include('accounting._nav')
    <p class="text-sm"><a class="text-indigo-600" href="{{ route('accounting.doc', $doc['id']) }}">← Back to document</a> · {{ $doc['party'] }} · Outstanding <b>{{ $m($doc['total_minor'] - $doc['paid_minor']) }} ৳</b></p>
    <form method="POST" action="{{ route('accounting.tax.settle.save', $doc['id']) }}" class="space-y-3 rounded-lg bg-white p-5 text-sm shadow">@csrf
        <div class="grid gap-2 sm:grid-cols-4">
            <label>Date<input type="date" name="date" value="{{ old('date', $today) }}" max="{{ $today }}" required class="mt-1 w-full rounded border px-2 py-1"></label>
            <label>Total against the document (৳)<input name="gross" required inputmode="decimal" value="{{ old('gross', number_format(($doc['total_minor'] - $doc['paid_minor']) / 100, 2, '.', '')) }}" class="mt-1 w-full rounded border px-2 py-1"></label>
            <label class="sm:col-span-2">{{ $bill ? 'Account the cash goes out of' : 'Account the cash comes into' }}<select name="account_id" class="mt-1 w-full rounded border px-2 py-1">@foreach ($cash as $a)<option value="{{ $a['id'] }}" @selected(old('account_id') === $a['id'])>{{ $a['code'] }} {{ $a['name_bn'] ?: $a['name'] }}</option>@endforeach</select></label>
        </div>
        <p class="font-semibold">{{ $bill ? 'What we deduct from the supplier’s payable' : 'What the customer deducted from us' }}</p>
        <table class="min-w-full"><thead class="text-left text-xs text-gray-500"><tr><th>Type</th><th>Rule (optional)</th><th>Base (blank = total)</th><th>Deducted amount (blank = per rule)</th><th>Certificate no.</th></tr></thead><tbody>
            @for ($i = 0; $i < 3; $i++)<tr>
                <td class="pr-2"><select name="rows[{{ $i }}][kind]" class="rounded border px-1 py-1"><option value="">—</option><option value="vds" @selected(old("rows.$i.kind") === 'vds')>VDS</option><option value="tds" @selected(old("rows.$i.kind") === 'tds')>TDS</option></select></td>
                <td class="pr-2"><select name="rows[{{ $i }}][rule]" class="rounded border px-1 py-1"><option value="">—</option>@foreach ($rules as $r)@if ($r['effective_to'] === null)<option value="{{ $r['code'] }}" @selected(old("rows.$i.rule") === $r['code'])>{{ strtoupper($r['kind']) }} {{ $r['code'] }} ({{ \App\Vat\VatCalc::percent($r['rate_bp']) }})</option>@endif @endforeach</select></td>
                <td class="pr-2"><input name="rows[{{ $i }}][base]" inputmode="decimal" value="{{ old("rows.$i.base") }}" class="w-28 rounded border px-1 py-1"></td>
                <td class="pr-2"><input name="rows[{{ $i }}][amount]" inputmode="decimal" value="{{ old("rows.$i.amount") }}" class="w-28 rounded border px-1 py-1"></td>
                <td><input name="rows[{{ $i }}][cert]" maxlength="40" value="{{ old("rows.$i.cert") }}" class="w-32 rounded border px-1 py-1"></td></tr>@endfor</tbody></table>
        <label class="block">Note<input name="note" maxlength="200" value="{{ old('note') }}" class="ml-2 w-80 rounded border px-1 py-1"></label>
        <p class="text-xs text-gray-500">{{ $bill ? 'Cash out = total − deducted tax; deducted tax "Tax payable" stays as a liability until you record the deposit to the treasury.' : 'Cash in = total − deducted tax; deducted tax "Advance income tax" stays as an asset (income does not reduce).' }} If nothing is deducted, use a normal payment.</p>
        <button class="rounded bg-indigo-600 px-3 py-1 text-white">Record</button>
    </form>
</x-layouts.app>
