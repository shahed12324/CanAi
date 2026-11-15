<x-layouts.admin title="Usage monitoring" :crumbs="['Usage monitoring' => null]">
    {{-- JARVIS ONE: page-specific visual identity; existing actions preserved --}}
    <header class="jarvis-module-hero jarvis-accent-violet" data-jarvis-module="admin" aria-label="Usage monitoring">
      <div class="jarvis-module-hero__identity"><span class="jarvis-module-hero__glyph" aria-hidden="true">✦</span><div>
        <div class="jarvis-module-hero__eyebrow">JARVIS ONE <span aria-hidden="true">/</span> ADMIN</div>
        <h1>Usage monitoring</h1><p>Manage your platform, permissions and operations.</p>
      </div></div>
      <span class="jarvis-module-hero__section">Admin Workspace</span>
    </header>
    <div class="flex flex-wrap items-center justify-between gap-3">
        <h1 class="text-xl font-semibold">Usage monitoring</h1>
        <form method="GET" class="text-sm"><select name="days" onchange="this.form.submit()" class="rounded border-gray-300 text-sm">@foreach ([7, 30, 90] as $d)<option value="{{ $d }}" @selected($days === $d)>Last {{ $d }} days</option>@endforeach</select></form>
    </div>
    <div class="rounded-lg bg-white p-4 shadow"><p class="text-xs text-gray-500">AI credits used by all shops</p><p class="text-2xl font-semibold tabular-nums">{{ number_format($total, 2) }}</p></div>

    <div class="grid gap-4 lg:grid-cols-2">
        <section class="rounded-lg bg-white p-5 shadow">
            <h2 class="mb-2 font-medium">Top shops by usage</h2>
            @forelse ($top as $r)
                <p class="flex justify-between gap-3 border-t py-1 text-sm"><a class="truncate text-indigo-600" href="{{ route('admin.tenants.show', $r->id) }}">{{ $r->name }}</a><span class="shrink-0 tabular-nums">{{ number_format((float) $r->spent, 2) }} <span class="text-xs text-gray-500">({{ $r->calls }} calls)</span></span></p>
            @empty<p class="text-sm text-gray-500">No usage yet.</p>@endforelse
        </section>
        <section class="rounded-lg bg-white p-5 shadow">
            <h2 class="mb-2 font-medium">Running low on credits</h2>
            @forelse ($low as $r)
                <p class="flex justify-between gap-3 border-t py-1 text-sm"><a class="truncate text-indigo-600" href="{{ route('admin.tenants.show', $r->id) }}">{{ $r->name }}</a><span class="shrink-0 tabular-nums">{{ number_format((float) $r->balance, 2) }}</span></p>
            @empty<p class="text-sm text-gray-500">No shop is low.</p>@endforelse
        </section>
    </div>

    <section class="rounded-lg bg-white p-5 shadow">
        <h2 class="mb-2 font-medium">Daily usage</h2>
        @php $max = max(1.0, (float) $daily->max('spent')); @endphp
        @forelse ($daily as $r)
            <div class="flex items-center gap-2 text-xs"><span class="w-20 text-gray-500">{{ $r->day }}</span><div class="h-2 rounded bg-indigo-500" style="width: {{ max(1, round(((float) $r->spent / $max) * 100)) }}%"></div><span class="tabular-nums">{{ number_format((float) $r->spent, 2) }}</span></div>
        @empty<p class="text-sm text-gray-500">No usage yet.</p>@endforelse
    </section>
</x-layouts.admin>
