<x-layouts.app title="{{ $a['name_bn'] ?: $a['name'] }} — Match">
    {{-- JARVIS ONE: page-specific visual identity; existing actions preserved --}}
    <header class="jarvis-module-hero jarvis-accent-violet" data-jarvis-module="accounting" aria-label="Accounting details">
      <div class="jarvis-module-hero__identity"><span class="jarvis-module-hero__glyph" aria-hidden="true">✦</span><div>
        <div class="jarvis-module-hero__eyebrow">JARVIS ONE <span aria-hidden="true">/</span> ACCOUNTING</div>
        <h1>Accounting details</h1><p>Review transactions, balances and accounting workflows.</p>
      </div></div>
      <span class="jarvis-module-hero__section">Accounting Workspace</span>
    </header>

    @php($m = fn ($v) => \App\Accounting\AccText::money((int) $v))
    <div class="flex flex-wrap items-center justify-between gap-2"><h2 class="text-xl font-semibold">{{ $a['code'] }} {{ $a['name_bn'] ?: $a['name'] }} — Statement matching</h2><a class="text-sm text-indigo-600" href="{{ route('accounting.bank') }}">← All accounts</a></div>
    @include('accounting._nav')
    <section class="grid gap-2 text-sm sm:grid-cols-4">
        <div class="rounded-lg bg-white p-3 shadow"><div class="text-xs text-gray-500">Balance in the books (today)</div><div class="font-semibold">{{ $m($rec['book_minor']) }}</div></div>
        <div class="rounded-lg bg-white p-3 shadow"><div class="text-xs text-gray-500">Statement closing balance</div><div class="font-semibold">{{ $rec['statement_minor'] === null ? '—' : $m($rec['statement_minor']) }}</div></div>
        <div class="rounded-lg bg-white p-3 shadow"><div class="text-xs text-gray-500">In the statement, not matched in the books</div><div class="font-semibold">{{ $rec['open_lines'] }} lines ({{ $m($rec['open_lines_minor']) }})</div></div>
        <div class="rounded-lg bg-white p-3 shadow"><div class="text-xs text-gray-500">In the books, not in the statement {{ $rec['since'] ? ' ('.$rec['since'].' from)' : '' }}</div><div class="font-semibold">{{ $rec['open_book_lines'] }} lines ({{ $m($rec['open_book_minor']) }})</div></div>
    </section>
    <p class="rounded px-3 py-2 text-sm {{ $rec['open_lines'] == 0 && $rec['matched_count'] > 0 ? 'bg-green-50 text-green-800' : 'bg-gray-50 text-gray-700' }}">
        {{ $rec['matched_count'] }} matched · {{ $rec['ignored_count'] }} skipped.
        @if ($rec['open_lines'] == 0 && $rec['matched_count'] > 0) ✔ Every statement line is in the books. @if ($rec['open_book_lines'] > 0) books: {{ $rec['open_book_lines'] }} lines did not appear in the statement (for example cheques not yet cleared) — these make up the difference between the book and statement balance. @endif @endif
    </p>
    @if ($canManage)
        <form method="POST" action="{{ route('accounting.bank.preview', $a['id']) }}" enctype="multipart/form-data" class="flex flex-wrap items-center gap-2 rounded-lg bg-white p-5 text-sm shadow">@csrf
            <span>Statement (CSV, max 2 MB):</span><input type="file" name="file" accept=".csv,.txt,text/csv" required class="text-sm"><button class="rounded bg-indigo-600 px-3 py-1 text-white">Preview</button>
            <span class="w-full text-xs text-gray-500">Download the statement as CSV/Excel from your bank website or app (if Excel, "CSV UTF-8" save as CSV). Before importing, check the columns and amount signs in the preview; importing the same file again does not create duplicates.</span></form>
        <form method="POST" action="{{ route('accounting.bank.auto', $a['id']) }}" class="text-sm">@csrf<button class="rounded border px-3 py-1">Match automatically (same amount, within 3 days)</button></form>
    @endif
    <div class="flex gap-3 text-sm">@foreach (['unmatched' => 'Unmatched', 'matched' => 'Match', 'ignored' => 'Dismissed', 'all' => 'All'] as $k => $t)<a class="{{ $status === $k ? 'font-semibold text-indigo-700 underline' : 'text-indigo-600' }}" href="{{ route('accounting.bank.show', [$a['id'], 'status' => $k]) }}">{{ $t }}</a>@endforeach</div>
    <div class="space-y-2">
        @forelse ($lines as $l)
            <div class="rounded-lg bg-white p-4 text-sm shadow">
                <div class="flex flex-wrap items-center justify-between gap-2"><div><span class="text-gray-500">{{ $l['txn_date'] }}</span> · {{ $l['description'] ?: '—' }}@if ($l['reference']) <span class="text-xs text-gray-500">({{ $l['reference'] }})</span>@endif</div>
                    <div class="font-semibold {{ $l['amount_minor'] < 0 ? 'text-red-700' : 'text-green-700' }}">{{ $l['amount_minor'] < 0 ? '−' : '+' }}{{ $m(abs($l['amount_minor'])) }}</div></div>
                @if ($l['status'] === 'matched')
                    <div class="mt-1 flex items-center gap-2 text-xs text-gray-600">Matched: <a class="text-indigo-600" href="{{ route('accounting.show', $l['entry_id']) }}">Entry #{{ $l['entry_number'] }}</a> {{ $l['entry_memo'] }}
                        @if ($canManage)<form method="POST" action="{{ route('accounting.bank.unmatch', $l['id']) }}">@csrf<button class="text-red-700">Unmatch</button></form>@endif</div>
                @elseif ($l['status'] === 'ignored')
                    <div class="mt-1 text-xs text-gray-500">Skipped @if ($canManage)<form method="POST" action="{{ route('accounting.bank.ignore', $l['id']) }}" class="inline">@csrf<input type="hidden" name="on" value="0"><button class="text-indigo-600">Restore</button></form>@endif</div>
                @elseif ($canManage)
                    @foreach ($sug[$l['id']] ?? [] as $s)
                        <form method="POST" action="{{ route('accounting.bank.match', $l['id']) }}" class="mt-1 flex flex-wrap items-center gap-2 text-xs">@csrf<input type="hidden" name="seq" value="{{ $s['journal_line_seq'] }}">Possible match: entry #{{ $s['entry_number'] }} ({{ $s['entry_date'] }}) {{ $s['memo'] }}<button class="rounded border px-2 py-0.5 text-indigo-700">This one</button></form>
                    @endforeach
                    <form method="POST" action="{{ route('accounting.bank.entry', $l['id']) }}" class="mt-2 flex flex-wrap items-center gap-2 text-xs">@csrf
                        <span>Not in the books — new entry:</span><select name="counter" class="rounded border px-1 py-0.5">@foreach ($counters as $c)<option value="{{ $c['id'] }}">{{ $c['code'] }} {{ $c['name_bn'] ?: $c['name'] }}</option>@endforeach</select><input name="memo" maxlength="200" placeholder="Description (optional)" class="rounded border px-1 py-0.5"><button class="rounded bg-indigo-600 px-2 py-0.5 text-white">Create entry</button></form>
                    <form method="POST" action="{{ route('accounting.bank.ignore', $l['id']) }}" class="mt-1 text-xs">@csrf<input type="hidden" name="on" value="1"><button class="text-gray-500">Skip this line</button></form>
                @endif
            </div>
        @empty<p class="rounded-lg bg-white p-6 text-center text-sm text-gray-500 shadow">No lines.</p>@endforelse
    </div>
    @if ($imports)<section class="rounded-lg bg-white p-4 text-xs text-gray-600 shadow"><h3 class="mb-1 font-semibold">Recent imports</h3>@foreach ($imports as $i)<div>{{ $i['created_at'] }} — {{ $i['filename'] }}: New {{ $i['rows_new'] }}, Earlier {{ $i['rows_dup'] }}</div>@endforeach</section>@endif
</x-layouts.app>
