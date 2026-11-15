<x-layouts.app title="VAT">
    {{-- JARVIS ONE: page-specific visual identity; existing actions preserved --}}
    <header class="jarvis-module-hero jarvis-accent-violet" data-jarvis-module="accounting" aria-label="VAT">
      <div class="jarvis-module-hero__identity"><span class="jarvis-module-hero__glyph" aria-hidden="true">✦</span><div>
        <div class="jarvis-module-hero__eyebrow">JARVIS ONE <span aria-hidden="true">/</span> ACCOUNTING</div>
        <h1>VAT</h1><p>Review transactions, balances and accounting workflows.</p>
      </div></div>
      <span class="jarvis-module-hero__section">Accounting Workspace</span>
    </header>

    @php($m = fn ($v) => \App\Accounting\AccText::money((int) $v))
    <h2 class="text-xl font-semibold">VAT — settings, rates and return</h2>
    @include('accounting._nav')
    <p class="rounded bg-amber-50 p-3 text-sm text-amber-800">All rates, periods and dates here are your own settings — NBR rules change and differ by source (for example whether returns are monthly or quarterly). <b>Check with your accountant/NBR.</b> This is a working draft built from the books; you submit the return on NBR’s own portal.</p>

    <section class="rounded-lg bg-white p-5 text-sm shadow">
        <p class="font-semibold">Registration and return period</p>
        @if ($canManage)
        <form method="POST" action="{{ route('accounting.vat.settings') }}" class="mt-2 grid gap-2 sm:grid-cols-4">@csrf
            <label>VAT registered?<select name="registered" class="mt-1 w-full rounded border px-2 py-1"><option value="1" @selected(old('registered', $s['registered'] ? '1' : '0') === '1')>Yes</option><option value="0" @selected(old('registered', $s['registered'] ? '1' : '0') === '0')>No</option></select></label>
            <label>BIN<input name="bin" value="{{ old('bin', $s['bin']) }}" inputmode="numeric" maxlength="20" class="mt-1 w-full rounded border px-2 py-1"></label>
            <label>Return<select name="period" class="mt-1 w-full rounded border px-2 py-1"><option value="monthly" @selected(old('period', $s['filing_period']) === 'monthly')>Monthly</option><option value="quarterly" @selected(old('period', $s['filing_period']) === 'quarterly')>Quarterly</option></select></label>
            <div class="self-end"><button class="rounded bg-indigo-600 px-3 py-1 text-white">Save</button></div>
        </form>
        @else<p>{{ $s['registered'] ? 'Registered, BIN '.$s['bin'] : 'Not registered' }} · {{ $s['filing_period'] === 'monthly' ? 'Monthly' : 'Quarterly' }}</p>@endif
        <p class="mt-2"><a class="text-indigo-600 underline" href="{{ route('accounting.vat.books') }}">Purchase and sales book and return draft for the current period →</a></p>
    </section>

    <section class="overflow-x-auto rounded-lg bg-white shadow"><p class="px-4 pt-3 font-semibold">VAT rates (date-based)</p>
        <table class="min-w-full text-sm"><thead class="bg-gray-50 text-left text-xs text-gray-500"><tr><th class="px-3 py-2">Code</th><th class="px-3 py-2">Name</th><th class="px-3 py-2 text-right">Rate</th><th class="px-3 py-2">Effective</th></tr></thead><tbody>
        @forelse ($rates as $r)<tr class="border-t"><td class="px-3 py-2 font-mono">{{ $r['code'] }}</td><td class="px-3 py-2">{{ $r['label'] }}</td><td class="px-3 py-2 text-right">{{ \App\Vat\VatCalc::percent($r['rate_bp']) }}</td><td class="px-3 py-2">{{ $r['effective_from'] }} → {{ $r['effective_to'] ?? 'Ongoing' }}</td></tr>
        @empty<tr><td colspan="4" class="px-3 py-6 text-center text-gray-500">No rates.</td></tr>@endforelse</tbody></table>
        @if ($canManage)
        <div class="space-y-2 p-4 text-sm">
            @unless ($rates)<form method="POST" action="{{ route('accounting.vat.seed') }}">@csrf<button class="rounded bg-gray-100 px-3 py-1">Add starter rates (15%, 7.5%, zero)</button></form>@endunless
            <form method="POST" action="{{ route('accounting.vat.rate') }}" class="grid gap-2 sm:grid-cols-5">@csrf
                <label>Code<input name="code" required maxlength="20" value="{{ old('code') }}" class="mt-1 w-full rounded border px-2 py-1"></label>
                <label>Name<input name="label" required maxlength="80" value="{{ old('label') }}" class="mt-1 w-full rounded border px-2 py-1"></label>
                <label>Rate (%)<input name="percent" required inputmode="decimal" value="{{ old('percent') }}" class="mt-1 w-full rounded border px-2 py-1"></label>
                <label>From date<input type="date" name="from" required value="{{ old('from', $today) }}" class="mt-1 w-full rounded border px-2 py-1"></label>
                <div class="self-end"><button class="rounded bg-indigo-600 px-3 py-1 text-white">Add rate</button></div>
                <p class="text-xs text-gray-500 sm:col-span-5">Adding a new rate for the same code ends the old one the day before. Rates only help calculate VAT on documents — already posted amounts in the books do not change.</p>
            </form>
        </div>
        @endif
    </section>

    <section class="overflow-x-auto rounded-lg bg-white shadow"><p class="px-4 pt-3 font-semibold">Saved returns</p>
        <table class="min-w-full text-sm"><thead class="bg-gray-50 text-left text-xs text-gray-500"><tr><th class="px-3 py-2">Period</th><th class="px-3 py-2">Status</th><th class="px-3 py-2 text-right">Output</th><th class="px-3 py-2 text-right">Input credit</th><th class="px-3 py-2 text-right">Net</th></tr></thead><tbody>
        @forelse ($returns as $r)<tr class="border-t"><td class="px-3 py-2"><a class="text-indigo-600" href="{{ route('accounting.vat.books', ['from' => $r['period_from'], 'to' => $r['period_to']]) }}">{{ $r['period_from'] }} – {{ $r['period_to'] }}</a></td>
            <td class="px-3 py-2">{{ $r['status'] === 'filed' ? 'Filed '.$r['filed_on'].($r['filed_ref'] ? ' ('.$r['filed_ref'].')' : '') : 'draft' }}</td>
            <td class="px-3 py-2 text-right">{{ $m($r['figures']['output_vat_minor']) }}</td><td class="px-3 py-2 text-right">{{ $m($r['figures']['input_vat_minor']) }}</td><td class="px-3 py-2 text-right font-semibold">{{ $m($r['figures']['net_minor']) }}</td></tr>
        @empty<tr><td colspan="5" class="px-3 py-6 text-center text-gray-500">No returns saved yet.</td></tr>@endforelse</tbody></table></section>
</x-layouts.app>
