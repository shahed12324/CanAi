<x-layouts.app title="Withholding tax (VDS/TDS/AIT)">
    {{-- JARVIS ONE: page-specific visual identity; existing actions preserved --}}
    <header class="jarvis-module-hero jarvis-accent-violet" data-jarvis-module="accounting" aria-label="Withholding tax (VDS/TDS/AIT)">
      <div class="jarvis-module-hero__identity"><span class="jarvis-module-hero__glyph" aria-hidden="true">✦</span><div>
        <div class="jarvis-module-hero__eyebrow">JARVIS ONE <span aria-hidden="true">/</span> ACCOUNTING</div>
        <h1>Withholding tax (VDS/TDS/AIT)</h1><p>Review transactions, balances and accounting workflows.</p>
      </div></div>
      <span class="jarvis-module-hero__section">Accounting Workspace</span>
    </header>

    @php($m = fn ($v) => \App\Accounting\AccText::money((int) $v))
    <h2 class="text-xl font-semibold">Tax deducted at source (VDS/TDS) and advance tax (AIT)</h2>
    @include('accounting._nav')
    <p class="rounded bg-amber-50 p-3 text-sm text-amber-800">What percentage must be withheld on which payment varies by law and type of goods/service. So in the rules below "rules"-you or your accountant enter the rate; the amount you confirm at payment time is what goes into the books. <b>Check rates and deposit deadlines with your accountant.</b></p>

    <section class="grid gap-3 text-sm sm:grid-cols-3">
        @foreach (['vds' => 'VDS (VAT deducted at source)', 'tds' => 'TDS (Income tax deducted at source)'] as $k => $label)
            <div class="rounded-lg bg-white p-4 shadow"><p class="font-semibold">{{ $label }}</p>
                <p>Pending deposit to government: <b class="{{ $sum['owed'][$k] > 0 ? 'text-red-700' : '' }}">{{ $m($sum['owed'][$k]) }} ৳</b></p>
                <p class="text-gray-600">Deposited: {{ $m($sum['deposited'][$k]) }} ৳</p>
                <p class="text-gray-600">Customer deducted from us: {{ $m($sum['suffered'][$k]) }} ৳</p></div>
        @endforeach
        <div class="rounded-lg bg-white p-4 shadow"><p class="font-semibold">Advance income tax (AIT)</p><p>Paid by ourselves: {{ $m($sum['advance_minor']) }} ৳</p></div>
    </section>

    @if ($canManage)
    <section class="rounded-lg bg-white p-5 text-sm shadow"><p class="font-semibold">Deposit to treasury</p>
        <form method="POST" action="{{ route('accounting.tax.deposit') }}" class="mt-2 grid gap-2 sm:grid-cols-6">@csrf
            <label>Type<select name="kind" class="mt-1 w-full rounded border px-2 py-1"><option value="vds">VDS</option><option value="tds">TDS</option></select></label>
            <label>Deposit date<input type="date" name="date" value="{{ old('date', $today) }}" max="{{ $today }}" required class="mt-1 w-full rounded border px-2 py-1"></label>
            <label>Deducted up to date<input type="date" name="upto" value="{{ old('upto', $today) }}" required class="mt-1 w-full rounded border px-2 py-1"></label>
            <label>Account paid from<select name="account_id" class="mt-1 w-full rounded border px-2 py-1">@foreach ($cash as $a)<option value="{{ $a['id'] }}">{{ $a['code'] }} {{ $a['name_bn'] ?: $a['name'] }}</option>@endforeach</select></label>
            <label>Challan/reference<input name="challan" maxlength="60" value="{{ old('challan') }}" class="mt-1 w-full rounded border px-2 py-1"></label>
            <div class="self-end"><button class="rounded bg-indigo-600 px-3 py-1 text-white">Record deposit</button></div>
            <p class="text-xs text-gray-500 sm:col-span-6">All remaining deducted tax (except from cancelled payments) will be treated as deposited together. You pay the money at the government challan/bank yourself — this is only the record.</p>
        </form></section>
    @endif

    <section class="overflow-x-auto rounded-lg bg-white shadow"><p class="px-4 pt-3 font-semibold">List of deducted taxes</p>
        <table class="min-w-full text-sm"><thead class="bg-gray-50 text-left text-xs text-gray-500"><tr><th class="px-3 py-2">Date</th><th class="px-3 py-2">Type</th><th class="px-3 py-2">Document</th><th class="px-3 py-2 text-right">Base</th><th class="px-3 py-2 text-right">Rate</th><th class="px-3 py-2 text-right">Tax</th><th class="px-3 py-2">Certificate</th><th class="px-3 py-2">Status</th></tr></thead><tbody>
        @forelse ($items as $w)<tr class="border-t {{ $w['cancelled'] ? 'text-gray-400 line-through' : '' }}"><td class="px-3 py-2">{{ $w['tax_date'] }}</td><td class="px-3 py-2">{{ strtoupper($w['kind']) }} {{ $w['direction'] === 'deducted' ? '(We deducted)' : '(Customer deducted)' }}</td>
            <td class="px-3 py-2"><a class="text-indigo-600" href="{{ route('accounting.doc', $w['doc_id']) }}">{{ $w['doc_number'] }}</a> {{ $w['party'] }}</td><td class="px-3 py-2 text-right">{{ $m($w['base_minor']) }}</td><td class="px-3 py-2 text-right">{{ \App\Vat\VatCalc::percent((int) $w['rate_bp']) }}</td><td class="px-3 py-2 text-right font-semibold">{{ $m($w['amount_minor']) }}</td>
            <td class="px-3 py-2">@if ($canManage && ! $w['cancelled'])<form method="POST" action="{{ route('accounting.tax.cert', $w['id']) }}" class="flex gap-1">@csrf<input name="cert" maxlength="40" value="{{ $w['cert_no'] }}" class="w-28 rounded border px-1 py-0.5 text-xs"><button class="rounded bg-gray-100 px-2 py-0.5 text-xs">OK</button></form>@else{{ $w['cert_no'] }}@endif</td>
            <td class="px-3 py-2">{{ $w['cancelled'] ? 'Payment cancelled' : ($w['direction'] === 'suffered' ? 'Advance tax (asset)' : ($w['deposit_id'] ? 'Deposited' : 'Pending deposit')) }}</td></tr>
        @empty<tr><td colspan="8" class="px-3 py-6 text-center text-gray-500">No deducted tax yet. From the bill/invoice page use "Pay with tax deducted" .</td></tr>@endforelse</tbody></table></section>

    <section class="overflow-x-auto rounded-lg bg-white shadow"><p class="px-4 pt-3 font-semibold">Deposit history</p>
        <table class="min-w-full text-sm"><tbody>@forelse ($deposits as $t)<tr class="border-t"><td class="px-3 py-2">{{ $t['deposit_date'] }}</td><td class="px-3 py-2">{{ strtoupper($t['kind']) }}</td><td class="px-3 py-2 text-right">{{ $m($t['amount_minor']) }} ৳</td><td class="px-3 py-2">{{ $t['account'] }}</td><td class="px-3 py-2">{{ $t['challan_ref'] }}</td></tr>
        @empty<tr><td class="px-3 py-6 text-center text-gray-500">No deposits.</td></tr>@endforelse</tbody></table></section>

    <section class="overflow-x-auto rounded-lg bg-white p-4 text-sm shadow"><p class="font-semibold">Advance income tax (AIT) — paid by ourselves</p>
        @if ($canManage)
        <form method="POST" action="{{ route('accounting.tax.advance') }}" class="mt-2 grid gap-2 sm:grid-cols-6">@csrf
            <label>Date<input type="date" name="date" value="{{ old('date', $today) }}" max="{{ $today }}" required class="mt-1 w-full rounded border px-2 py-1"></label>
            <label>Amount (৳)<input name="amount" required inputmode="decimal" value="{{ old('amount') }}" class="mt-1 w-full rounded border px-2 py-1"></label>
            <label>Account paid from<select name="account_id" class="mt-1 w-full rounded border px-2 py-1">@foreach ($cash as $a)<option value="{{ $a['id'] }}">{{ $a['code'] }} {{ $a['name_bn'] ?: $a['name'] }}</option>@endforeach</select></label>
            <label>Reference<input name="ref" maxlength="60" value="{{ old('ref') }}" class="mt-1 w-full rounded border px-2 py-1"></label>
            <label>Note<input name="note" maxlength="200" value="{{ old('note') }}" class="mt-1 w-full rounded border px-2 py-1"></label>
            <div class="self-end"><button class="rounded bg-indigo-600 px-3 py-1 text-white">Recorded</button></div></form>
        @endif
        <table class="mt-3 min-w-full"><tbody>@forelse ($advances as $a)<tr class="border-t {{ $a['voided_at'] ? 'text-gray-400 line-through' : '' }}"><td class="px-3 py-2">{{ $a['tax_date'] }}</td><td class="px-3 py-2 text-right">{{ $m($a['amount_minor']) }} ৳</td><td class="px-3 py-2">{{ $a['account'] }}</td><td class="px-3 py-2">{{ $a['reference'] }} {{ $a['note'] }}</td>
            <td class="px-3 py-2">@if ($canManage && ! $a['voided_at'])<form method="POST" action="{{ route('accounting.tax.advance.void', $a['id']) }}">@csrf<button class="text-xs text-red-700">Cancel</button></form>@endif</td></tr>
        @empty<tr><td class="px-3 py-4 text-center text-gray-500">No AIT.</td></tr>@endforelse</tbody></table></section>

    <section class="overflow-x-auto rounded-lg bg-white shadow"><p class="px-4 pt-3 font-semibold">Tax deduction rules (date-based helper rates)</p>
        <table class="min-w-full text-sm"><thead class="bg-gray-50 text-left text-xs text-gray-500"><tr><th class="px-3 py-2">Type</th><th class="px-3 py-2">Code</th><th class="px-3 py-2">Name</th><th class="px-3 py-2 text-right">Rate</th><th class="px-3 py-2">Effective</th></tr></thead><tbody>
        @forelse ($rules as $r)<tr class="border-t"><td class="px-3 py-2">{{ strtoupper($r['kind']) }}</td><td class="px-3 py-2 font-mono">{{ $r['code'] }}</td><td class="px-3 py-2">{{ $r['label'] }}</td><td class="px-3 py-2 text-right">{{ \App\Vat\VatCalc::percent($r['rate_bp']) }}</td><td class="px-3 py-2">{{ $r['effective_from'] }} → {{ $r['effective_to'] ?? 'Ongoing' }}</td></tr>
        @empty<tr><td colspan="5" class="px-3 py-6 text-center text-gray-500">No rules — add below (for example "TDS-SUPPLY"), or enter the amount directly at payment time.</td></tr>@endforelse</tbody></table>
        @if ($canManage)<form method="POST" action="{{ route('accounting.tax.rule') }}" class="grid gap-2 p-4 text-sm sm:grid-cols-6">@csrf
            <label>Type<select name="kind" class="mt-1 w-full rounded border px-2 py-1"><option value="vds">VDS</option><option value="tds">TDS</option></select></label>
            <label>Code<input name="code" required maxlength="20" value="{{ old('code') }}" class="mt-1 w-full rounded border px-2 py-1"></label>
            <label>Name<input name="label" required maxlength="80" value="{{ old('label') }}" class="mt-1 w-full rounded border px-2 py-1"></label>
            <label>Rate (%)<input name="percent" required inputmode="decimal" value="{{ old('percent') }}" class="mt-1 w-full rounded border px-2 py-1"></label>
            <label>From date<input type="date" name="from" required value="{{ old('from', $today) }}" class="mt-1 w-full rounded border px-2 py-1"></label>
            <div class="self-end"><button class="rounded bg-indigo-600 px-3 py-1 text-white">Add rule</button></div></form>@endif
    </section>
</x-layouts.app>
