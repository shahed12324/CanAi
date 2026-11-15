<x-layouts.app title="Auditor">
    {{-- JARVIS ONE: page-specific visual identity; existing actions preserved --}}
    <header class="jarvis-module-hero jarvis-accent-violet" data-jarvis-module="accounting" aria-label="Auditor">
      <div class="jarvis-module-hero__identity"><span class="jarvis-module-hero__glyph" aria-hidden="true">✦</span><div>
        <div class="jarvis-module-hero__eyebrow">JARVIS ONE <span aria-hidden="true">/</span> ACCOUNTING</div>
        <h1>Auditor</h1><p>Review transactions, balances and accounting workflows.</p>
      </div></div>
      <span class="jarvis-module-hero__section">Accounting Workspace</span>
    </header>

    @php($m = fn ($v) => \App\Accounting\AccText::money((int) $v))
    @php($sevClass = ['high' => 'bg-red-100 text-red-800', 'medium' => 'bg-amber-100 text-amber-800', 'low' => 'bg-gray-100 text-gray-700'])
    <h2 class="text-xl font-semibold">Auditor</h2>
    @include('accounting._nav')
    <p class="rounded bg-amber-50 p-3 text-sm text-amber-800">What is shown here is <b>Questions, not verdicts</b>. The program searches the books and flags suspicious items; you or your auditor decide whether it is really a problem. Nothing is changed or deleted automatically.</p>
    @if ($errors->any())<p class="rounded bg-red-50 p-3 text-sm text-red-700">{{ $errors->first() }}</p>@endif
    @if (session('status'))<p class="rounded bg-green-50 p-3 text-sm text-green-700">{{ session('status') }}</p>@endif

    <section class="rounded-lg bg-white p-4 text-sm shadow"><p class="font-semibold">Current status (automatic checks)</p>
        <ul class="mt-2 grid gap-1 sm:grid-cols-2">@foreach ($health as $h)<li class="flex items-center justify-between rounded border px-3 py-1"><span>{{ \App\Audit\AuditText::HEALTH[$h['key']] ?? $h['key'] }}</span>
            <span class="{{ $h['ok'] ? 'text-green-700' : 'font-semibold text-red-700' }}">{{ \App\Audit\AuditText::healthLine($h['key'], $h['ok'], $h['count'], $h['amount_minor']) }}</span></li>@endforeach</ul></section>

    <section class="rounded-lg bg-white p-4 text-sm shadow">
        <div class="flex flex-wrap items-center justify-between gap-2"><p class="font-semibold">Finding</p>
            @if ($canAudit)<form method="POST" action="{{ route('accounting.audit.scan') }}">@csrf<button class="rounded bg-indigo-600 px-3 py-1 text-white">Scan the books</button></form>@endif</div>
        <div class="mt-2 flex flex-wrap gap-2">@foreach (['open' => 'Open', 'confirmed' => 'Confirmed', 'dismissed' => 'Dismissed', 'all' => 'All'] as $k => $v)
            <a href="{{ route('accounting.audit', ['status' => $k]) }}" class="rounded px-3 py-1 {{ $status === $k ? 'bg-indigo-600 text-white' : 'bg-gray-100' }}">{{ $v }}@if ($k !== 'all') ({{ $counts[$k] }})@endif</a>@endforeach</div>
        <div class="mt-3 space-y-2">@forelse ($findings as $f)
            <div class="rounded border p-3"><div class="flex flex-wrap items-center gap-2"><span class="rounded px-2 py-0.5 text-xs {{ $sevClass[$f['severity']] }}">{{ \App\Audit\AuditText::SEVERITY[$f['severity']] }}</span>
                <span class="font-semibold">{{ \App\Audit\AuditText::RULES[$f['rule']] ?? $f['rule'] }}</span><span class="text-xs text-gray-500">{{ \App\Audit\AuditText::STATUS[$f['status']] }}</span>
                @if ($f['subject_type'] === 'doc' && $f['subject_id'])<a class="text-xs text-indigo-600" href="{{ route('accounting.doc', $f['subject_id']) }}">View document →</a>@endif</div>
                <p class="mt-1">{{ \App\Audit\AuditText::explain($f['rule'], $f['amount_minor'], $f['detail']) }}</p>
                @if ($f['note'])<p class="mt-1 text-xs text-gray-600">Comment: {{ $f['note'] }}@if ($f['reviewer']) — {{ $f['reviewer'] }}@endif</p>@endif
                @if ($canAudit)<form method="POST" action="{{ route('accounting.audit.review', $f['id']) }}" class="mt-2 flex flex-wrap gap-1">@csrf
                    <input name="note" maxlength="300" placeholder="Comment/reason ('Dismissed' required to dismiss)" value="{{ $f['note'] }}" class="w-64 rounded border px-2 py-1 text-xs">
                    @if ($f['status'] !== 'confirmed')<button name="status" value="confirmed" class="rounded bg-red-100 px-2 py-1 text-xs text-red-800">Confirm: there is a problem</button>@endif
                    @if ($f['status'] !== 'dismissed')<button name="status" value="dismissed" class="rounded bg-green-100 px-2 py-1 text-xs text-green-800">Dismiss: no problem</button>@endif
                    @if ($f['status'] !== 'open')<button name="status" value="open" class="rounded bg-gray-100 px-2 py-1 text-xs">Reopen</button>@endif</form>@endif</div>
        @empty<p class="py-6 text-center text-gray-500">Nothing in this list.@if ($canAudit) "Scan the books" Press.@endif</p>@endforelse</div></section>

    <section class="rounded-lg bg-white p-4 text-sm shadow"><div class="flex flex-wrap items-center justify-between gap-2"><p class="font-semibold">Monthly audit checklist</p>
        <form method="GET" action="{{ route('accounting.audit') }}" class="flex items-center gap-1"><input type="hidden" name="status" value="{{ $status }}"><input type="month" name="period" value="{{ $period }}" max="{{ substr($today, 0, 7) }}" class="rounded border px-2 py-1"><button class="rounded bg-gray-100 px-2 py-1">View</button></form></div>
        <ul class="mt-2 space-y-1">@forelse ($checklist as $i)<li class="flex flex-wrap items-center justify-between gap-2 rounded border px-3 py-1 {{ $i['active'] ? '' : 'text-gray-400' }}">
            <span>{{ $i['done_on'] ? '☑' : '☐' }} {{ $i['label'] }}@if ($i['done_on']) <span class="text-xs text-gray-500">({{ $i['done_on'] }}@if ($i['note']) · {{ $i['note'] }}@endif)</span>@endif</span>
            <span class="flex gap-1">
            @if ($canAudit && $i['active'])
                @if ($i['done_on'])<form method="POST" action="{{ route('accounting.audit.untick', $i['id']) }}">@csrf<input type="hidden" name="period" value="{{ $period }}"><button class="rounded bg-gray-100 px-2 py-0.5 text-xs">Tick</button></form>
                @else<form method="POST" action="{{ route('accounting.audit.tick', $i['id']) }}" class="flex gap-1">@csrf<input type="hidden" name="period" value="{{ $period }}"><input type="date" name="on" value="{{ $today }}" max="{{ $today }}" required class="rounded border px-1 py-0.5 text-xs"><input name="note" maxlength="200" placeholder="Note" class="w-28 rounded border px-1 py-0.5 text-xs"><button class="rounded bg-indigo-600 px-2 py-0.5 text-xs text-white">Done</button></form>@endif
            @endif
            @if ($canSettings)<form method="POST" action="{{ route('accounting.audit.item.active', $i['id']) }}">@csrf<input type="hidden" name="active" value="{{ $i['active'] ? 0 : 1 }}"><button class="rounded bg-gray-100 px-2 py-0.5 text-xs">{{ $i['active'] ? 'Closed' : 'Active' }}</button></form>@endif</span></li>
        @empty<li class="py-4 text-center text-gray-500">No items.@if ($canSettings)<form method="POST" action="{{ route('accounting.audit.seed') }}" class="mt-2">@csrf<button class="rounded bg-indigo-600 px-3 py-1 text-white">Use the starter checklist</button></form>@endif</li>@endforelse</ul>
        @if ($canSettings)<form method="POST" action="{{ route('accounting.audit.item') }}" class="mt-3 flex flex-wrap gap-2">@csrf<input name="label" maxlength="120" required placeholder="New item" class="w-72 rounded border px-2 py-1"><input name="sort" type="number" min="0" max="1000" value="100" class="w-20 rounded border px-2 py-1"><button class="rounded bg-indigo-600 px-3 py-1 text-white">Add</button></form>@endif</section>

    @if ($canSettings)
    <section class="rounded-lg bg-white p-4 text-sm shadow"><p class="font-semibold">Suspicion thresholds</p>
        <form method="POST" action="{{ route('accounting.audit.settings') }}" class="mt-2 grid gap-2 sm:grid-cols-4">@csrf
            <label>Duplicate: within how many days<input type="number" name="dup_days" min="0" max="30" value="{{ old('dup_days', $settings['dup_days']) }}" required class="mt-1 w-full rounded border px-2 py-1"></label>
            <label>Unusually large: how many times<input type="number" name="outlier_factor" min="2" max="100" value="{{ old('outlier_factor', $settings['outlier_factor']) }}" required class="mt-1 w-full rounded border px-2 py-1"></label>
            <label>Minimum earlier documents for comparison<input type="number" name="min_history" min="3" max="100" value="{{ old('min_history', $settings['min_history']) }}" required class="mt-1 w-full rounded border px-2 py-1"></label>
            <label>Back-dated: more than how many days late<input type="number" name="backdate_days" min="1" max="365" value="{{ old('backdate_days', $settings['backdate_days']) }}" required class="mt-1 w-full rounded border px-2 py-1"></label>
            <label>Cash payment limit (৳)<input name="cash" value="{{ old('cash', $m($settings['cash_limit_minor'])) }}" required class="mt-1 w-full rounded border px-2 py-1"></label>
            <label>"Round amounts": Multiple of (৳)<input name="round_modulus" value="{{ old('round_modulus', $m($settings['round_modulus'])) }}" required class="mt-1 w-full rounded border px-2 py-1"></label>
            <label>Start checking round amounts (from ৳)<input name="round_min" value="{{ old('round_min', $m($settings['round_min_minor'])) }}" required class="mt-1 w-full rounded border px-2 py-1"></label>
            <div class="self-end"><button class="rounded bg-indigo-600 px-3 py-1 text-white">Save</button></div></form></section>
    @endif
</x-layouts.app>
