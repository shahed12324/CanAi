<x-layouts.app title="Ad drafts">
    {{-- JARVIS ONE: page-specific visual identity; existing actions preserved --}}
    <header class="jarvis-module-hero jarvis-accent-violet" data-jarvis-module="ads" aria-label="Ad drafts">
      <div class="jarvis-module-hero__identity"><span class="jarvis-module-hero__glyph" aria-hidden="true">✦</span><div>
        <div class="jarvis-module-hero__eyebrow">JARVIS ONE <span aria-hidden="true">/</span> ADS</div>
        <h1>Ad drafts</h1><p>Plan campaigns, review creatives and manage ad performance.</p>
      </div></div>
      <span class="jarvis-module-hero__section">Ads Workspace</span>
    </header>

    <p class="text-sm"><a class="text-indigo-600 hover:underline" href="{{ route('ads.index') }}">← Meta Ad</a></p>
    <h2 class="mt-1 text-xl font-semibold">Ad drafts</h2>
    <p class="mt-1 rounded bg-amber-50 p-3 text-sm text-amber-900">The draft stays only in this system — <b>Meta-nothing is sent and no money is spent.</b> Without a spending limit nothing can be submitted or approved. An approved draft will be created in the next step "paused (PAUSED)" state.</p>
    @if ($errors->any())<p class="mt-2 rounded bg-red-50 p-3 text-sm text-red-700">{{ $errors->first() }}</p>@endif
    @if (session('status'))<p class="mt-2 rounded bg-green-50 p-3 text-sm text-green-700">{{ session('status') }}</p>@endif
    @if (session('error'))<p class="mt-2 rounded bg-red-50 p-3 text-sm text-red-700">{{ session('error') }}</p>@endif

    <section class="mt-3 rounded-lg bg-white p-4 text-sm shadow"><h3 class="font-semibold">Spending limit</h3>
        @if ($caps)<p class="mt-1">Daily maximum: <b>{{ number_format((float) $caps['max_daily'], 2) }}</b> · Maximum total in one campaign (daily × days): <b>{{ number_format((float) $caps['max_total'], 2) }}</b> (in the account currency)</p>
        @else<p class="mt-1 text-red-700">No limit is set — so no draft can be submitted or approved.</p>@endif
        @if ($can_caps)<form method="POST" action="{{ route('ads.caps') }}" class="mt-2 flex flex-wrap items-end gap-2">@csrf
            <label class="grid gap-1">Daily maximum<input name="max_daily" inputmode="decimal" required value="{{ $caps['max_daily'] ?? '' }}" class="w-32 rounded border px-2 py-1"></label>
            <label class="grid gap-1">Total maximum<input name="max_total" inputmode="decimal" required value="{{ $caps['max_total'] ?? '' }}" class="w-32 rounded border px-2 py-1"></label>
            <button class="rounded bg-indigo-600 px-3 py-1 text-white">Save limit</button></form>@endif
    </section>

    @if ($can_manage)<p class="mt-3">@if ($accounts === [])<span class="text-sm text-gray-600">First connect an ad account for drafts.</span>@else<a class="rounded bg-indigo-600 px-3 py-1 text-sm text-white" href="{{ route('ads.draft.create') }}">+ New draft</a>@endif</p>@endif

    <section class="mt-3 rounded-lg bg-white p-4 text-sm shadow">
        @forelse ($drafts as $d)
            <div class="flex flex-wrap items-center gap-2 border-t pt-2 first:border-0 first:pt-0"><a class="font-semibold text-indigo-700 hover:underline" href="{{ route('ads.draft', $d['id']) }}">{{ $d['name'] }}</a>
                <span class="rounded bg-gray-100 px-2 text-xs">{{ \App\Ads\AdsText::OBJECTIVES[$d['objective']] ?? $d['objective'] }}</span>
                <span class="rounded px-2 text-xs {{ $d['status'] === 'approved' ? 'bg-green-100 text-green-800' : ($d['status'] === 'pending' ? 'bg-amber-100 text-amber-800' : 'bg-gray-100') }}">{{ \App\Ads\AdsText::STATUS[$d['status']] ?? $d['status'] }}</span>
                <span class="text-xs text-gray-500">Daily {{ number_format((float) $d['daily_budget'], 2) }} {{ $d['currency'] }} × {{ $d['days'] }} days · {{ $d['author'] }}</span></div>
        @empty<p class="text-gray-500">No drafts yet.</p>@endforelse
    </section>
</x-layouts.app>
