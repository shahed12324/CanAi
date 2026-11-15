<x-layouts.admin title="White-label" :crumbs="['White-label' => null]">
    {{-- JARVIS ONE: page-specific visual identity; existing actions preserved --}}
    <header class="jarvis-module-hero jarvis-accent-violet" data-jarvis-module="admin" aria-label="White-label">
      <div class="jarvis-module-hero__identity"><span class="jarvis-module-hero__glyph" aria-hidden="true">✦</span><div>
        <div class="jarvis-module-hero__eyebrow">JARVIS ONE <span aria-hidden="true">/</span> ADMIN</div>
        <h1>White-label</h1><p>Manage your platform, permissions and operations.</p>
      </div></div>
      <span class="jarvis-module-hero__section">Admin Workspace</span>
    </header>
    <h1 class="text-xl font-semibold">White-label</h1>
    @if ($errors->any())<p class="rounded bg-red-50 p-3 text-sm text-red-700">{{ $errors->first() }}</p>@endif
    @if (session('status'))<p class="rounded bg-green-50 p-3 text-sm text-green-700">{{ session('status') }}</p>@endif
    <div class="space-y-2">
        @forelse ($rows as $r)
        <div class="flex flex-wrap items-center justify-between gap-2 rounded-lg bg-white p-3 text-sm shadow">
            <span><b>{{ $r->name }}</b> <span class="text-xs text-gray-500">{{ $r->slug }} · Brand: {{ $r->brand_name ?? '—' }} {{ $r->color ?? '' }}</span></span>
            <form method="POST" action="{{ route('admin.brands.disable', trim($r->tenant_id)) }}" onsubmit="return confirm('Turning off will remove the brand. Sure?')">@csrf<button class="text-xs text-red-700 underline">Deactivate</button></form>
        </div>
        @empty<p class="text-sm text-gray-500">Not enabled in any workspace.</p>@endforelse
    </div>
    <section class="max-w-md space-y-3 rounded-lg bg-white p-5 shadow"><h2 class="font-medium">Activate</h2>
        <form method="POST" action="{{ route('admin.brands.enable') }}" class="space-y-3">@csrf
            <x-field name="slug" label="Workspace slug" required />
            <x-button>Activate</x-button></form></section>
</x-layouts.admin>
