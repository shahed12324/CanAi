<x-layouts.app title="Daily report">
    {{-- JARVIS ONE: page-specific visual identity; existing actions preserved --}}
    <header class="jarvis-module-hero jarvis-accent-violet" data-jarvis-module="couriers" aria-label="Daily report">
      <div class="jarvis-module-hero__identity"><span class="jarvis-module-hero__glyph" aria-hidden="true">✦</span><div>
        <div class="jarvis-module-hero__eyebrow">JARVIS ONE <span aria-hidden="true">/</span> COURIERS</div>
        <h1>Daily report</h1>
      </div></div>
      <span class="jarvis-module-hero__section">Couriers Workspace</span>
    </header>

    <div class="flex items-center justify-between"><h2 class="text-xl font-semibold">Daily order report</h2><a class="text-sm text-indigo-600" href="{{ route('orders.index') }}">← Order</a></div>
    <section class="rounded-lg bg-white p-5 text-sm shadow"><h3 class="mb-2 font-semibold">Today ({{ $today }}) — So far</h3>
        <ul class="list-disc space-y-1 pl-5">@foreach (\App\Couriers\ReportText::lines($live) as $l)<li>{{ $l }}</li>@endforeach</ul></section>
    @foreach ($saved as $r)
        <section class="rounded-lg bg-white p-5 text-sm shadow"><h3 class="mb-2 font-semibold">{{ $r['day'] }}</h3>
            <ul class="list-disc space-y-1 pl-5">@foreach (\App\Couriers\ReportText::lines($r['data']) as $l)<li>{{ $l }}</li>@endforeach</ul></section>
    @endforeach
    @if (! $saved)<p class="text-sm text-gray-500">No saved reports — yesterday’s report is created every day at 12:10 AM.</p>@endif
</x-layouts.app>
