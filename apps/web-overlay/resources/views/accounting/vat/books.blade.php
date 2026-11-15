<x-layouts.app title="VAT — purchase and sales book">
    {{-- JARVIS ONE: page-specific visual identity; existing actions preserved --}}
    <header class="jarvis-module-hero jarvis-accent-violet" data-jarvis-module="accounting" aria-label="VAT — purchase and sales book">
      <div class="jarvis-module-hero__identity"><span class="jarvis-module-hero__glyph" aria-hidden="true">✦</span><div>
        <div class="jarvis-module-hero__eyebrow">JARVIS ONE <span aria-hidden="true">/</span> ACCOUNTING</div>
        <h1>VAT — purchase and sales book</h1><p>Review transactions, balances and accounting workflows.</p>
      </div></div>
      <span class="jarvis-module-hero__section">Accounting Workspace</span>
    </header>

    @php($m = fn ($v) => \App\Accounting\AccText::money((int) $v))
    @php($q = ['from' => $from, 'to' => $to])
    <h2 class="text-xl font-semibold">VAT — purchase and sales book and return draft</h2>
    @include('accounting._nav')
    <form method="GET" class="flex flex-wrap items-end gap-2 rounded-lg bg-white p-4 text-sm shadow">
        <label> from<input type="date" name="from" value="{{ $from }}" class="mt-1 block rounded border px-2 py-1"></label>
        <label>Until<input type="date" name="to" value="{{ $to }}" class="mt-1 block rounded border px-2 py-1"></label>
        <button class="rounded bg-gray-100 px-3 py-1">View</button>
        <a class="text-indigo-600 underline" href="{{ route('accounting.vat.books', ['from' => $prev[0], 'to' => $prev[1]]) }}">← Previous period</a>
        <a class="text-indigo-600 underline" href="{{ route('accounting.vat') }}">Setting/rate</a>
    </form>

    <section class="rounded-lg bg-white p-5 text-sm shadow">
        <p class="font-semibold">Return draft ({{ $from }} – {{ $to }})</p>
        <table class="mt-2 text-sm"><tbody>
            <tr><td class="pr-6">Sales ({{ $figures['sales_count'] }} invoices) — value</td><td class="text-right">{{ $m($figures['sales_base_minor']) }} ৳</td></tr>
            <tr><td class="pr-6">Output VAT</td><td class="text-right">{{ $m($figures['output_vat_minor']) }} ৳</td></tr>
            <tr><td class="pr-6">Purchases ({{ $figures['purchase_count'] }} bills/expenses) — value</td><td class="text-right">{{ $m($figures['purchase_base_minor']) }} ৳</td></tr>
            <tr><td class="pr-6">Input VAT — credit can be taken</td><td class="text-right">{{ $m($figures['input_vat_claimable_minor']) }} ৳</td></tr>
            <tr class="text-gray-500"><td class="pr-6">Input VAT — credit cannot be taken</td><td class="text-right">{{ $m($figures['input_vat_blocked_minor']) }} ৳</td></tr>
            <tr class="border-t font-semibold"><td class="pr-6">{{ $figures['net_minor'] >= 0 ? 'Net VAT payable' : 'Credit carried to next period' }}</td><td class="text-right">{{ $m(abs($figures['net_minor'])) }} ৳</td></tr>
        </tbody></table>
        <p class="mt-2 text-xs text-gray-500">Cancelled (void) documents are excluded. Credit carried from the previous period, supplementary duty and VDS deduction (17b) are not counted here — add them to the return by hand. {{ $s['registered'] ? '' : 'Settings say you are not registered.' }}</p>
        @if ($filed)<p class="mt-2 rounded bg-green-50 p-2 text-green-700">Filed: {{ $filed['filed_on'] }} {{ $filed['filed_ref'] ? '('.$filed['filed_ref'].')' : '' }} — Net {{ $m($filed['figures']['net_minor']) }} ৳ @if ((int) $filed['figures']['net_minor'] !== (int) $figures['net_minor'])<b class="text-red-700">· Books changed after filing (now {{ $m($figures['net_minor']) }} ৳) — an amendment may be needed.</b>@endif</p>
        @elseif ($canManage)
        <form method="POST" action="{{ route('accounting.vat.return') }}" class="mt-3 flex flex-wrap gap-2">@csrf<input type="hidden" name="from" value="{{ $from }}"><input type="hidden" name="to" value="{{ $to }}">
            <input name="note" maxlength="200" placeholder="Note (optional)" class="rounded border px-2 py-1"><button class="rounded bg-indigo-600 px-3 py-1 text-white">Save draft</button></form>
        @if ($draft)
        <form method="POST" action="{{ route('accounting.vat.file', $draft['id']) }}" class="mt-2 flex flex-wrap gap-2">@csrf
            <input type="date" name="on" value="{{ $today }}" max="{{ $today }}" required class="rounded border px-2 py-1"><input name="ref" maxlength="60" placeholder="NBR Reference (optional)" class="rounded border px-2 py-1"><button class="rounded bg-gray-100 px-3 py-1">NBR-I have deposited at — record it</button></form>
        @endif
        @endif
    </section>

    <section class="overflow-x-auto rounded-lg bg-white shadow"><p class="px-4 pt-3 font-semibold">Sales book (VAT-6.2 style) <a class="ml-2 text-xs text-indigo-600 underline" href="{{ route('accounting.vat.csv', ['which' => 'sales'] + $q) }}">CSV</a></p>
        <table class="min-w-full text-sm"><thead class="bg-gray-50 text-left text-xs text-gray-500"><tr><th class="px-3 py-2">Number</th><th class="px-3 py-2">Date</th><th class="px-3 py-2">Customer</th><th class="px-3 py-2 text-right">Value</th><th class="px-3 py-2 text-right">VAT</th></tr></thead><tbody>
        @forelse ($sales as $r)<tr class="border-t"><td class="px-3 py-2">{{ $r['number'] }}</td><td class="px-3 py-2">{{ $r['issue_date'] }}</td><td class="px-3 py-2">{{ $r['party'] }}</td><td class="px-3 py-2 text-right">{{ $m($r['subtotal_minor']) }}</td><td class="px-3 py-2 text-right">{{ $m($r['vat_minor']) }}</td></tr>
        @empty<tr><td colspan="5" class="px-3 py-6 text-center text-gray-500">No invoices in this period.</td></tr>@endforelse</tbody></table></section>

    <section class="overflow-x-auto rounded-lg bg-white shadow"><p class="px-4 pt-3 font-semibold">Purchase book (VAT-6.1 style) <a class="ml-2 text-xs text-indigo-600 underline" href="{{ route('accounting.vat.csv', ['which' => 'purchases'] + $q) }}">CSV</a></p>
        <table class="min-w-full text-sm"><thead class="bg-gray-50 text-left text-xs text-gray-500"><tr><th class="px-3 py-2">Number</th><th class="px-3 py-2">Date</th><th class="px-3 py-2">Supplier</th><th class="px-3 py-2 text-right">Value</th><th class="px-3 py-2 text-right">VAT</th><th class="px-3 py-2">Credits</th></tr></thead><tbody>
        @forelse ($purchases as $r)<tr class="border-t"><td class="px-3 py-2">{{ $r['number'] }}</td><td class="px-3 py-2">{{ $r['issue_date'] }}</td><td class="px-3 py-2">{{ $r['party'] }}</td><td class="px-3 py-2 text-right">{{ $m($r['subtotal_minor']) }}</td><td class="px-3 py-2 text-right">{{ $m($r['vat_minor']) }}</td>
            <td class="px-3 py-2">@if ((int) $r['vat_minor'] === 0)—@else
                @if ($canManage && ! $filed)<form method="POST" action="{{ route('accounting.vat.flag', $r['id']) }}" class="flex flex-wrap items-center gap-1">@csrf
                    <select name="creditable" class="rounded border px-1 py-0.5 text-xs"><option value="1" @selected($r['creditable'])>Can take</option><option value="0" @selected(! $r['creditable'])>Cannot take</option></select>
                    <input name="reason" maxlength="120" value="{{ $r['reason'] ?? '' }}" placeholder="Reason (if not)" class="w-36 rounded border px-1 py-0.5 text-xs"><button class="rounded bg-gray-100 px-2 py-0.5 text-xs">OK</button></form>
                @else{{ $r['creditable'] ? 'Can take' : 'No — '.($r['reason'] ?? '') }}@endif
            @endif</td></tr>
        @empty<tr><td colspan="6" class="px-3 py-6 text-center text-gray-500">No bills/expenses in this period.</td></tr>@endforelse</tbody></table></section>
</x-layouts.app>
