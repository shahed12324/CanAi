<x-layouts.app title="Enterprise License">
    {{-- JARVIS ONE: page-specific visual identity; existing actions preserved --}}
    <header class="jarvis-module-hero jarvis-accent-violet" data-jarvis-module="license" aria-label="Enterprise License">
      <div class="jarvis-module-hero__identity"><span class="jarvis-module-hero__glyph" aria-hidden="true">✦</span><div>
        <div class="jarvis-module-hero__eyebrow">JARVIS ONE <span aria-hidden="true">/</span> LICENSE</div>
        <h1>Enterprise License</h1>
      </div></div>
      <span class="jarvis-module-hero__section">License Workspace</span>
    </header>

    <h2 class="text-xl font-semibold">Enterprise License</h2>
    @if ($l === null)
        <p class="mt-3 rounded-lg bg-white p-4 text-sm shadow">This workspace has no Enterprise license (the member count follows your plan).</p>
    @else
        <div class="mt-3 grid max-w-md gap-1 rounded-lg bg-white p-4 text-sm shadow">
            <p>Seats: <b>{{ $l['used'] }}</b> / {{ $l['seats'] }} Used · free: <b>{{ $l['left'] }}</b></p>
            <p>Expires: {{ \Illuminate\Support\Str::of($l['expires_at'])->substr(0, 10) }} @if ($l['expired'])<span class="text-red-700">(Expired — new members cannot be added unless renewed)</span>@endif</p>
            @if ($l['note'] !== '')<p class="text-xs text-gray-600">{{ $l['note'] }}</p>@endif
        </div>
    @endif
</x-layouts.app>
