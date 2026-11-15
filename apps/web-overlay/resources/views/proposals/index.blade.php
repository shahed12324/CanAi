<x-layouts.app title="Proposals">
    {{-- JARVIS ONE: page-specific visual identity; existing actions preserved --}}
    <header class="jarvis-module-hero jarvis-accent-violet" data-jarvis-module="proposals" aria-label="Proposals">
      <div class="jarvis-module-hero__identity"><span class="jarvis-module-hero__glyph" aria-hidden="true">✦</span><div>
        <div class="jarvis-module-hero__eyebrow">JARVIS ONE <span aria-hidden="true">/</span> PROPOSALS</div>
        <h1>Proposals</h1>
      </div></div>
      <span class="jarvis-module-hero__section">Proposals Workspace</span>
    </header>

    <div class="flex flex-wrap items-center justify-between gap-2"><h2 class="text-xl font-semibold">Proposals</h2>
        <div class="flex gap-3 text-sm"><a class="text-indigo-600" href="{{ route('brand.show') }}">Brand kit</a><a class="text-indigo-600" href="{{ route('portfolio.index') }}">Portfolio</a>@if ($canManage)<a class="rounded bg-indigo-600 px-3 py-1 text-white" href="{{ route('proposals.create') }}">New proposal</a>@endif</div></div>
    @if (session('status'))<p class="rounded bg-green-50 p-3 text-sm text-green-700">{{ session('status') }}</p>@endif
    <section class="rounded-lg bg-white p-5 shadow">
        @forelse ($rows as $r)
            <a href="{{ route('proposals.edit', trim($r->id)) }}" class="flex flex-wrap items-center justify-between gap-2 border-t py-2 text-sm first:border-0 hover:bg-gray-50">
                <span><strong>{{ $r->title }}</strong>@if ($r->lead_name) <span class="text-gray-500">· {{ $r->lead_name }}</span>@endif</span>
                <span class="text-xs text-gray-600">{{ $statusBn[$r->status] ?? $r->status }} · {{ \Illuminate\Support\Carbon::parse($r->updated_at)->diffForHumans() }}</span>
            </a>
        @empty<p class="text-sm text-gray-500">No proposals yet.@if ($canManage) <a class="text-indigo-600" href="{{ route('proposals.create') }}">Create the first one</a>.@endif</p>@endforelse
    </section>
</x-layouts.app>
