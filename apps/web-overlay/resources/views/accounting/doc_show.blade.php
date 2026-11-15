<x-layouts.app title="{{ $doc['number'] }}">
    {{-- JARVIS ONE: page-specific visual identity; existing actions preserved --}}
    <header class="jarvis-module-hero jarvis-accent-violet" data-jarvis-module="accounting" aria-label="Accounting — Doc Show">
      <div class="jarvis-module-hero__identity"><span class="jarvis-module-hero__glyph" aria-hidden="true">✦</span><div>
        <div class="jarvis-module-hero__eyebrow">JARVIS ONE <span aria-hidden="true">/</span> ACCOUNTING</div>
        <h1>Accounting — Doc Show</h1><p>Review transactions, balances and accounting workflows.</p>
      </div></div>
      <span class="jarvis-module-hero__section">Accounting Workspace</span>
    </header>

    @php($m = fn ($v) => \App\Accounting\AccText::money((int) $v))
    <div class="flex items-center justify-between"><h2 class="text-xl font-semibold">{{ \App\Accounting\AccText::kindBn($doc['kind']) }} {{ $doc['number'] }} <span class="ml-1 rounded bg-gray-100 px-2 py-0.5 text-xs font-normal">{{ \App\Accounting\AccText::statusBn($doc['status']) }}</span></h2><a class="text-sm text-indigo-600" href="{{ route('accounting.show', $doc['entry_id']) }}">Journal entry</a></div>
    @include('accounting._nav')
    <section class="rounded-lg bg-white p-5 text-sm shadow"><p>{{ $doc['party'] ?: '—' }} · Issued {{ $doc['issue_date'] }}@if ($doc['kind'] !== 'expense') · Due date {{ $doc['due_date'] }}@endif @if ($doc['reference']) · Ref {{ $doc['reference'] }}@endif @if ($doc['pay_account']) · {{ $doc['pay_account'] }} from @endif</p></section>
    <div class="overflow-x-auto rounded-lg bg-white shadow"><table class="min-w-full text-sm"><thead class="bg-gray-50 text-left text-xs text-gray-500"><tr><th class="px-3 py-2">Account</th><th class="px-3 py-2">Description</th><th class="px-3 py-2 text-right">Amount</th></tr></thead><tbody>
        @foreach ($lines as $l)<tr class="border-t"><td class="px-3 py-2">{{ $l['code'] }} {{ $l['name_bn'] ?: $l['name'] }}</td><td class="px-3 py-2">{{ $l['description'] }}</td><td class="px-3 py-2 text-right">{{ $m($l['amount_minor']) }}</td></tr>@endforeach
        @if ($doc['vat_minor'] > 0)<tr class="border-t"><td colspan="2" class="px-3 py-1 text-right">VAT</td><td class="px-3 py-1 text-right">{{ $m($doc['vat_minor']) }}</td></tr>@endif
        <tr class="border-t font-semibold"><td colspan="2" class="px-3 py-2 text-right">Total</td><td class="px-3 py-2 text-right">{{ $m($doc['total_minor']) }}</td></tr>
        @if ($doc['kind'] !== 'expense')<tr><td colspan="2" class="px-3 py-1 text-right">Paid</td><td class="px-3 py-1 text-right">{{ $m($doc['paid_minor']) }}</td></tr><tr class="font-semibold"><td colspan="2" class="px-3 py-1 text-right">Outstanding</td><td class="px-3 py-1 text-right">{{ $doc['status'] === 'void' ? '—' : $m($doc['total_minor'] - $doc['paid_minor']) }}</td></tr>@endif</tbody></table></div>
    @if ($payments)<section class="rounded-lg bg-white p-5 text-sm shadow"><h3 class="mb-2 font-semibold">Payment</h3><ul class="space-y-2">@foreach ($payments as $p)<li class="flex flex-wrap items-center gap-2 {{ $p['voided_at'] ? 'text-gray-400 line-through' : '' }}">{{ $p['pay_date'] }} — {{ $m($p['amount_minor']) }} ({{ $p['account'] }})@if ($canManage && ! $p['voided_at'])<form method="POST" action="{{ route('accounting.payment.void', $p['id']) }}" class="flex gap-1">@csrf<input type="date" name="date" value="{{ $today }}" max="{{ $today }}" class="rounded border px-1 text-xs"><button class="text-xs text-red-700">Cancel</button></form>@endif</li>@endforeach</ul></section>@endif
    @if ($canManage && $doc['status'] === 'open' && $doc['kind'] !== 'expense')
        @if ($canManage && in_array($doc['kind'], ['bill', 'invoice'], true))<p class="text-sm"><a class="text-indigo-600 underline" href="{{ route('accounting.tax.settle', $doc['id']) }}">Payment with tax withheld (VDS/TDS) →</a></p>@endif
        <form method="POST" action="{{ route('accounting.doc.pay', $doc['id']) }}" class="flex flex-wrap items-end gap-2 rounded-lg bg-white p-5 text-sm shadow">@csrf
            <label>Date<input type="date" name="pay_date" value="{{ $today }}" max="{{ $today }}" required class="ml-1 rounded border px-1 py-1"></label><label>Amount<input name="amount" value="{{ old('amount', number_format(($doc['total_minor'] - $doc['paid_minor']) / 100, 2, '.', '')) }}" inputmode="decimal" required class="ml-1 w-28 rounded border px-1 py-1"></label>
            <label>{{ $doc['kind'] === 'invoice' ? 'Account received into' : 'Account it went from' }}<select name="account_id" class="ml-1 rounded border px-1 py-1">@foreach ($cash as $c)<option value="{{ $c['account_id'] }}">{{ $c['name_bn'] ?: $c['name'] }}</option>@endforeach</select></label>
            <input name="note" maxlength="200" placeholder="Note" class="rounded border px-1 py-1"><button class="rounded bg-indigo-600 px-3 py-1 text-white">{{ $doc['kind'] === 'invoice' ? 'Received payment' : 'Made payment' }}</button></form>
    @endif
    @if ($canManage && $doc['status'] !== 'void')
        <form method="POST" action="{{ route('accounting.doc.void', $doc['id']) }}" class="flex items-center gap-2 text-sm">@csrf<input type="date" name="date" value="{{ $today }}" max="{{ $today }}" class="rounded border px-1 py-1"><button class="rounded border px-3 py-1">Cancel this document</button><span class="text-xs text-gray-500">(if there are payments, cancel those first)</span></form>
    @endif
</x-layouts.app>
