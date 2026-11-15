<x-layouts.admin title="Enterprise License" :crumbs="['License' => null]">
    {{-- JARVIS ONE: page-specific visual identity; existing actions preserved --}}
    <header class="jarvis-module-hero jarvis-accent-violet" data-jarvis-module="admin" aria-label="Enterprise License">
      <div class="jarvis-module-hero__identity"><span class="jarvis-module-hero__glyph" aria-hidden="true">✦</span><div>
        <div class="jarvis-module-hero__eyebrow">JARVIS ONE <span aria-hidden="true">/</span> ADMIN</div>
        <h1>Enterprise License</h1><p>Manage your platform, permissions and operations.</p>
      </div></div>
      <span class="jarvis-module-hero__section">Admin Workspace</span>
    </header>
    <h1 class="text-xl font-semibold">Enterprise License</h1>
    @if ($errors->any())<p class="rounded bg-red-50 p-3 text-sm text-red-700">{{ $errors->first() }}</p>@endif
    @if (session('status'))<p class="rounded bg-green-50 p-3 text-sm text-green-700">{{ session('status') }}</p>@endif
    <div class="space-y-2">
        @forelse ($rows as $r)
        <div class="flex flex-wrap items-center justify-between gap-2 rounded-lg bg-white p-3 text-sm shadow">
            <span><b>{{ $r->name }}</b> <span class="text-xs text-gray-500">{{ $r->slug }} · Seats {{ $r->used }}/{{ $r->seats }} · Expiry {{ \Illuminate\Support\Str::of((string) $r->expires_at)->substr(0, 10) }}@if (strtotime((string) $r->expires_at) <= time()) <span class="text-red-700">(End)</span>@endif @if ($r->note !== '')· {{ $r->note }}@endif</span>
            <form method="POST" action="{{ route('admin.licenses.remove', trim($r->tenant_id)) }}" onsubmit="return confirm('Removing the license removes the seat limit. Sure?')">@csrf<button class="text-xs text-red-700 underline">Remove</button></form>
        </div>
        @empty<p class="text-sm text-gray-500">No license.</p>@endforelse
    </div>
    <section class="max-w-md space-y-3 rounded-lg bg-white p-5 shadow"><h2 class="font-medium">Set / change license</h2>
        <form method="POST" action="{{ route('admin.licenses.set') }}" class="space-y-3">@csrf
            <x-field name="slug" label="Workspace slug" required />
            <x-field name="seats" label="Number of seats" required />
            <x-field name="expires" label="Expires (YYYY-MM-DD)" required />
            <x-field name="note" label="Note" />
            <x-button>Save</x-button></form></section>
</x-layouts.admin>
