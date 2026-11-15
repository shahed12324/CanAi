<x-layouts.app title="Bill scan — draft">
    {{-- JARVIS ONE: page-specific visual identity; existing actions preserved --}}
    <header class="jarvis-module-hero jarvis-accent-violet" data-jarvis-module="accounting" aria-label="Bill scan — draft">
      <div class="jarvis-module-hero__identity"><span class="jarvis-module-hero__glyph" aria-hidden="true">✦</span><div>
        <div class="jarvis-module-hero__eyebrow">JARVIS ONE <span aria-hidden="true">/</span> ACCOUNTING</div>
        <h1>Bill scan — draft</h1><p>Review transactions, balances and accounting workflows.</p>
      </div></div>
      <span class="jarvis-module-hero__section">Accounting Workspace</span>
    </header>

    @php($flagText = ['single_line_from_total' => 'No separate items found — one line was made from the total', 'foreign_currency' => 'A currency other than taka (৳) was seen — check the amounts', 'no_total' => 'The bill total was not found', 'total_mismatch' => 'The sum of items does not match the bill total', 'no_date' => 'No date found — today’s date used', 'due_before_issue' => 'Due date was before the issue date', 'low_confidence' => 'AI Not sure itself — check everything'])
    <h2 class="text-xl font-semibold">Bill scan — {{ $s['file_name'] }}</h2>
    @include('accounting._nav')
    <p><a class="text-sm text-indigo-600" href="{{ route('accounting.billscan') }}">← All scans</a></p>

    @if ($s['status'] === 'used')
        <p class="rounded bg-green-50 p-3 text-sm text-green-700">This bill has been posted to the books. <a class="underline" href="{{ route('accounting.doc', $s['doc_id']) }}">View document</a></p>
    @elseif ($s['status'] === 'failed')
        <p class="rounded bg-red-50 p-3 text-sm text-red-700">{{ \App\Accounting\AccText::billScanFailure($s['error']) }}</p>
    @elseif ($s['status'] === 'discarded')
        <p class="rounded bg-gray-50 p-3 text-sm text-gray-700">Cancelled.</p>
    @elseif ($s['status'] === 'scanning')
        <p class="rounded bg-amber-50 p-3 text-sm text-amber-700">Scan in progress… refresh the page in a moment.</p>
    @endif

    @if ($form)
    <div class="grid gap-4 lg:grid-cols-2">
        <section class="rounded-lg bg-white p-3 shadow">
            @if ($s['mime'] === 'application/pdf')
                <iframe src="{{ route('accounting.billscan.file', $s['id']) }}" sandbox class="h-[70vh] w-full rounded border" title="Bills"></iframe>
            @else
                <img src="{{ route('accounting.billscan.file', $s['id']) }}" alt="Uploaded bill" class="max-h-[70vh] w-full rounded border object-contain">
            @endif
        </section>
        <section class="space-y-3">
            <p class="rounded bg-amber-50 p-3 text-sm text-amber-800">AI-read draft — <b> may be wrong</b>. Compare with the image, correct it and "post to the books" Press.@if ($form['total'] !== '') Bill total: <b>{{ $form['total'] }} ৳</b>.@endif</p>
            @if ($s['flags'])<ul class="list-disc rounded bg-white p-3 pl-7 text-sm text-red-700 shadow">@foreach ($s['flags'] as $f)<li>{{ $flagText[$f] ?? $f }}</li>@endforeach</ul>@endif
            @if ($canManage)
            <form method="POST" action="{{ route('accounting.billscan.post', $s['id']) }}" class="space-y-3 rounded-lg bg-white p-5 text-sm shadow">@csrf
                <div class="grid gap-2 sm:grid-cols-2">
                    <label>Type<select name="kind" class="mt-1 w-full rounded border px-2 py-1"><option value="bill" @selected(old('kind', $form['kind']) === 'bill')>Bill (on credit — pay later)</option><option value="expense" @selected(old('kind', $form['kind']) === 'expense')>Expense (paid now)</option></select></label>
                    <label>Date<input type="date" name="issue_date" value="{{ old('issue_date', $form['issue_date']) }}" max="{{ $today }}" required class="mt-1 w-full rounded border px-2 py-1"></label>
                    <label>Supplier<select name="party_id" class="mt-1 w-full rounded border px-2 py-1"><option value="">— New / none —</option>@foreach ($suppliers as $p)<option value="{{ $p['id'] }}" @selected(old('party_id', $form['party_id']) === $p['id'])>{{ $p['name'] }}</option>@endforeach</select></label>
                    <label>New supplier name (if not above)<input name="new_supplier" maxlength="120" value="{{ old('new_supplier', $form['new_supplier']) }}" class="mt-1 w-full rounded border px-2 py-1"></label>
                    <label>Due date (for bill)<input type="date" name="due_date" value="{{ old('due_date', $form['due_date']) }}" class="mt-1 w-full rounded border px-2 py-1"></label>
                    <label>Bill number / reference<input name="reference" maxlength="60" value="{{ old('reference', $form['reference']) }}" class="mt-1 w-full rounded border px-2 py-1"></label>
                    <label class="sm:col-span-2">For an expense — where the money came from<select name="pay_account" class="mt-1 w-full rounded border px-2 py-1">@foreach ($cash as $c)<option value="{{ $c['account_id'] }}" @selected(old('pay_account') === $c['account_id'])>{{ $c['code'] }} {{ $c['name_bn'] ?: $c['name'] }}</option>@endforeach</select></label>
                </div>
                <table class="min-w-full"><thead class="text-left text-xs text-gray-500"><tr><th>Account</th><th>Description</th><th>Amount (৳)</th></tr></thead><tbody>
                    @php($n = max(5, count($form['rows']) + 1))
                    @for ($i = 0; $i < $n; $i++)@php($row = $form['rows'][$i] ?? ['account_id' => '', 'description' => '', 'amount' => ''])
                        <tr><td class="pr-2"><select name="rows[{{ $i }}][account_id]" class="w-full rounded border px-1 py-1"><option value="">— Choose —</option>@foreach ($accounts as $a)<option value="{{ $a['id'] }}" @selected(old("rows.$i.account_id", $row['account_id']) === $a['id'])>{{ $a['code'] }} {{ $a['name_bn'] ?: $a['name'] }}</option>@endforeach</select></td>
                            <td class="pr-2"><input name="rows[{{ $i }}][description]" value="{{ old("rows.$i.description", $row['description']) }}" maxlength="200" class="w-full rounded border px-1 py-1"></td><td><input name="rows[{{ $i }}][amount]" value="{{ old("rows.$i.amount", $row['amount']) }}" inputmode="decimal" class="w-28 rounded border px-1 py-1"></td></tr>@endfor</tbody></table>
                <label class="block">VAT/tax (৳; optional)<input name="vat" value="{{ old('vat', $form['vat']) }}" inputmode="decimal" class="ml-2 w-32 rounded border px-1 py-1"></label>
                <button class="rounded bg-indigo-600 px-3 py-1 text-white"> post to the books</button>
            </form>
            <form method="POST" action="{{ route('accounting.billscan.discard', $s['id']) }}" onsubmit="return confirm('Discard this draft?')">@csrf<button class="rounded bg-gray-100 px-3 py-1 text-sm">Discard draft</button></form>
            @endif
        </section>
    </div>
    @endif
</x-layouts.app>
