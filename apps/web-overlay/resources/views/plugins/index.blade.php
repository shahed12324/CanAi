<x-layouts.app title="Plugin store">
    {{-- JARVIS ONE: page-specific visual identity; existing actions preserved --}}
    <header class="jarvis-module-hero jarvis-accent-violet" data-jarvis-module="plugins" aria-label="Plugin store">
      <div class="jarvis-module-hero__identity"><span class="jarvis-module-hero__glyph" aria-hidden="true">✦</span><div>
        <div class="jarvis-module-hero__eyebrow">JARVIS ONE <span aria-hidden="true">/</span> PLUGINS</div>
        <h1>Plugin store</h1>
      </div></div>
      <span class="jarvis-module-hero__section">Plugins Workspace</span>
    </header>

    <h2 class="text-xl font-semibold">Plugin store</h2>
    <p class="mt-1 text-sm text-gray-600">A plugin is a ready-made workflow pack. No outside code runs in it; installing creates your own copy — <b> in a disabled state</b>, you review it and turn it on yourself. Steps that send anything outside still need your approval as before.</p>
    @if ($errors->has('plugin'))<p class="mt-2 rounded bg-red-50 p-3 text-sm text-red-700">{{ $errors->first('plugin') }}</p>@endif
    @if (session('status'))<p class="mt-2 rounded bg-green-50 p-3 text-sm text-green-700">{{ session('status') }}</p>@endif

    <section class="mt-3 grid gap-3 sm:grid-cols-2">
        @forelse ($plugins as $p)
        <div class="rounded-lg bg-white p-4 text-sm shadow"><b>{{ $p['name'] }}</b> <span class="text-xs text-gray-500">v{{ $p['version'] }} · {{ $p['slug'] }}</span>
            @if ($p['description'] !== '')<p class="mt-1 text-xs text-gray-700">{{ $p['description'] }}</p>@endif
            <p class="mt-2 text-xs font-semibold">What it can do:</p>
            @if (count($p['permissions']) === 0)<p class="text-xs text-gray-600">Uses no tools (only AI and text).</p>@endif
            <ul class="text-xs">@foreach ($p['permissions'] as $x)<li>· {{ $x['label'] }} — <span class="{{ $x['risk'] === 'external' ? 'text-amber-700' : 'text-gray-600' }}">{{ $x['risk_label'] }}</span></li>@endforeach</ul>
            <div class="mt-2 flex items-center gap-3">
                @if ($p['installed'] === null)
                    <form method="POST" action="{{ route('plugins.install', $p['slug']) }}">@csrf<button class="rounded bg-indigo-600 px-3 py-1 text-white">Install</button></form>
                @else
                    <span class="text-xs text-green-700">Installed (v {{ $p['installed'] }})</span>
                    @if ($p['installed'] < $p['version'])<form method="POST" action="{{ route('plugins.install', $p['slug']) }}" onsubmit="return confirm('On update the plugin’s workflows change to the new version — changes you made to your copy will be lost. On/off state is kept.')">@csrf<button class="rounded bg-amber-600 px-3 py-1 text-white">v{{ $p['version'] }}-Update to</button></form>@endif
                    <form method="POST" action="{{ route('plugins.uninstall', $p['slug']) }}" onsubmit="return confirm('The plugin and its workflows (including run history) will be deleted. Sure?')">@csrf<button class="text-xs text-red-700 underline">Delete</button></form>
                @endif
            </div>
        </div>
        @empty<p class="text-sm text-gray-500">No plugins in the store yet.</p>@endforelse
    </section>

    <section class="mt-4 rounded-lg bg-white p-4 text-sm shadow"><h3 class="font-semibold">Submit your own plugin</h3>
        <p class="mt-1 text-xs text-gray-600">The manifest is JSON: <code>{"workflows": [{"name": "…", "description": "…", "category": "personal", "definition": {…}}]}</code> (1–5 workflows; definition "Agent" same as the page). After submission an admin reads it and approves or rejects; submit a new version again with the same ID.</p>
        @if ($errors->has('submit'))<p class="mt-2 rounded bg-red-50 p-3 text-xs text-red-700">{{ $errors->first('submit') }}</p>@endif
        <form method="POST" action="{{ route('plugins.submit') }}" class="mt-2 grid gap-2">@csrf
            <div class="flex flex-wrap gap-2"><label class="grid text-xs">ID (a-z, 0-9, -)<input name="slug" maxlength="40" required value="{{ old('slug') }}" class="rounded border px-2 py-1 text-sm"></label>
            <label class="grid flex-1 text-xs">Name<input name="name" maxlength="80" required value="{{ old('name') }}" class="rounded border px-2 py-1 text-sm"></label></div>
            <label class="grid text-xs">Description<input name="description" maxlength="400" value="{{ old('description') }}" class="rounded border px-2 py-1 text-sm"></label>
            <label class="grid text-xs">Manifest (JSON)<textarea name="manifest" rows="8" maxlength="60000" required class="rounded border px-2 py-1 font-mono text-xs">{{ old('manifest') }}</textarea></label>
            <div><button class="rounded bg-indigo-600 px-3 py-1 text-white">Submit</button></div></form>
        @foreach ($mine as $m)
        <div class="mt-2 flex flex-wrap items-center justify-between gap-2 rounded border p-2 text-xs"><span><b>{{ $m['name'] }}</b> · {{ $m['slug'] }} v{{ $m['version'] }} ·
            @switch($m['status'])@case('published')<span class="text-green-700">Published</span>@break @case('rejected')<span class="text-red-700">Cancel @if ($m['review_note']): {{ $m['review_note'] }}@endif</span>@break @case('withdrawn')<span class="text-gray-500">Withdraw</span>@break @default<span class="text-amber-700">Waiting</span>@endswitch</span>
            @if (in_array($m['status'], ['pending', 'published'], true))<form method="POST" action="{{ route('plugins.withdraw', $m['id']) }}">@csrf<button class="text-red-700 underline">Withdraw</button></form>@endif</div>
        @endforeach
    </section>
</x-layouts.app>
