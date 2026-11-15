<x-layouts.app title="Books lock">
    {{-- JARVIS ONE: page-specific visual identity; existing actions preserved --}}
    <header class="jarvis-module-hero jarvis-accent-violet" data-jarvis-module="accounting" aria-label="Books lock">
      <div class="jarvis-module-hero__identity"><span class="jarvis-module-hero__glyph" aria-hidden="true">✦</span><div>
        <div class="jarvis-module-hero__eyebrow">JARVIS ONE <span aria-hidden="true">/</span> ACCOUNTING</div>
        <h1>Books lock</h1><p>Review transactions, balances and accounting workflows.</p>
      </div></div>
      <span class="jarvis-module-hero__section">Accounting Workspace</span>
    </header>

    <h2 class="text-xl font-semibold">Books closed (lock)</h2>
    @include('accounting._nav')
    <section class="space-y-3 rounded-lg bg-white p-5 text-sm shadow">
        <p>{{ $s['locked_through'] ? '🔒 '.$s['locked_through'].' Closed until — no entry or reversal on or before this date.' : 'There is no lock now.' }}</p>
        @if ($canSettings)
            <form method="POST" action="{{ route('accounting.lock') }}" class="flex flex-wrap items-center gap-2">@csrf<input type="date" name="through" max="{{ $today }}" required class="rounded border px-2 py-1"><button class="rounded bg-indigo-600 px-3 py-1 text-white">Lock until this date</button></form>
            @if ($s['locked_through'])
                <form method="POST" action="{{ route('accounting.unlock') }}" class="flex flex-wrap items-center gap-2">@csrf<input type="date" name="to" class="rounded border px-2 py-1" title="Empty = open fully"><input name="reason" required minlength="5" maxlength="200" placeholder="Reason for opening" class="rounded border px-2 py-1"><button class="rounded border px-3 py-1">Move lock back / open</button></form>
            @endif
        @endif
    </section>
    <section class="rounded-lg bg-white p-5 text-sm shadow"><h3 class="mb-2 font-semibold">Lock history</h3><ul class="space-y-1">@forelse ($log as $l)<li>{{ \Illuminate\Support\Carbon::parse($l['created_at'])->format('d M Y H:i') }} — {{ $l['action'] === 'lock' ? 'Lock' : 'Unlock' }} → {{ $l['to_date'] ?? 'All open' }}@if ($l['reason']) <i>({{ $l['reason'] }})</i>@endif</li>@empty<li class="text-gray-500">None.</li>@endforelse</ul></section>
</x-layouts.app>
