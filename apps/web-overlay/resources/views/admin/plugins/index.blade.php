<x-layouts.admin title="Plugin review" :crumbs="['Plugin review' => null]">
    {{-- JARVIS ONE: page-specific visual identity; existing actions preserved --}}
    <header class="jarvis-module-hero jarvis-accent-violet" data-jarvis-module="admin" aria-label="Plugin review">
      <div class="jarvis-module-hero__identity"><span class="jarvis-module-hero__glyph" aria-hidden="true">✦</span><div>
        <div class="jarvis-module-hero__eyebrow">JARVIS ONE <span aria-hidden="true">/</span> ADMIN</div>
        <h1>Plugin review</h1><p>Manage your platform, permissions and operations.</p>
      </div></div>
      <span class="jarvis-module-hero__section">Admin Workspace</span>
    </header>
    <h1 class="text-xl font-semibold">Plugin review</h1>
    <p class="text-sm text-gray-600">Read each version’s full manifest — which tools it uses, what it writes, and whether it sends anything outside. Nothing goes to the store without approval.</p>
    @if ($errors->any())<p class="rounded bg-red-50 p-3 text-sm text-red-700">{{ $errors->first() }}</p>@endif
    @if (session('status'))<p class="rounded bg-green-50 p-3 text-sm text-green-700">{{ session('status') }}</p>@endif
    <div class="space-y-3">
        @forelse ($items as $i)
        <div class="rounded-lg bg-white p-4 shadow text-sm">
            <b>{{ $i['name'] }}</b> <span class="text-xs text-gray-500">{{ $i['slug'] }} v{{ $i['version'] }} · Author: {{ $i['author'] }} · {{ $i['created_at'] }}</span>
            @if ($i['description'] !== '')<p class="mt-1">{{ $i['description'] }}</p>@endif
            <p class="mt-2 text-xs font-semibold">Declared tools:</p>
            @if (count($i['permissions']) === 0)<p class="text-xs">No tools.</p>@endif
            <ul class="text-xs">@foreach ($i['permissions'] as $x)<li>· <code>{{ $x['key'] }}</code> — {{ $x['risk_label'] }}</li>@endforeach</ul>
            <details class="mt-2"><summary class="cursor-pointer text-xs text-indigo-700">View manifest</summary><pre class="mt-1 max-h-96 overflow-auto rounded bg-gray-900 p-3 text-xs text-gray-100">{{ $i['manifest'] }}</pre></details>
            <form method="POST" action="{{ route('admin.plugins.decide', $i['id']) }}" class="mt-2 flex flex-wrap items-end gap-2">@csrf
                <input name="note" maxlength="300" placeholder="Note (required if rejecting)" class="min-w-0 flex-1 rounded border border-gray-300 px-2 py-1">
                <button name="decision" value="published" class="rounded bg-green-600 px-3 py-1 text-white">Publish</button>
                <button name="decision" value="rejected" class="rounded bg-red-600 px-3 py-1 text-white">Cancel</button></form>
        </div>
        @empty<p class="text-sm text-gray-500">No plugins awaiting review.</p>@endforelse
    </div>
</x-layouts.admin>
