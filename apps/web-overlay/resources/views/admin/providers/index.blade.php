<x-layouts.admin title="AI Provider" :crumbs="['AI Provider' => null]">
    {{-- JARVIS ONE: page-specific visual identity; existing actions preserved --}}
    <header class="jarvis-module-hero jarvis-accent-violet" data-jarvis-module="admin" aria-label="AI Provider">
      <div class="jarvis-module-hero__identity"><span class="jarvis-module-hero__glyph" aria-hidden="true">✦</span><div>
        <div class="jarvis-module-hero__eyebrow">JARVIS ONE <span aria-hidden="true">/</span> ADMIN</div>
        <h1>AI Provider</h1><p>Manage your platform, permissions and operations.</p>
      </div></div>
      <span class="jarvis-module-hero__section">Admin Workspace</span>
    </header>
    <h1 class="text-xl font-semibold">AI Provider and health</h1>
    <div class="overflow-x-auto rounded-lg bg-white shadow">
        <table class="min-w-full text-sm">
            <thead class="bg-gray-50 text-left text-gray-600"><tr><th class="px-4 py-2">Name</th><th class="px-4 py-2">Adapter</th><th class="px-4 py-2">Status</th><th class="px-4 py-2">API key</th><th class="px-4 py-2">Model</th><th class="px-4 py-2">Last check</th><th class="px-4 py-2">Uptime 24h</th><th class="px-4 py-2">Avg response</th></tr></thead>
            <tbody>
            @forelse ($providers as $p)
                <tr class="border-t">
                    <td class="px-4 py-2"><a class="text-indigo-600" href="{{ route('admin.providers.show', $p->id) }}">{{ $p->name }}</a></td>
                    <td class="px-4 py-2">{{ $p->adapter }}</td>
                    <td class="px-4 py-2">{{ $p->status }}</td>
                    <td class="px-4 py-2">{{ $p->credential_id ? 'Present' : '—' }}</td>
                    <td class="px-4 py-2">{{ $p->models }}</td>
                    <td class="px-4 py-2">{{ $p->last_check ?? '—' }}</td>
                    <td class="px-4 py-2">{{ $p->uptime_24h !== null ? $p->uptime_24h.'%' : '—' }}</td>
                    <td class="px-4 py-2">{{ $p->avg_latency !== null ? $p->avg_latency.' ms' : '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="8" class="px-4 py-6 text-center text-gray-500">No providers.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <section class="max-w-xl space-y-3 rounded-lg bg-white p-5 shadow">
        <h2 class="font-medium">New Provider</h2>
        <form method="POST" action="{{ route('admin.providers.store') }}" class="space-y-3">
            @csrf
            <x-field name="name" label="Name" required />
            <div class="grid gap-3 sm:grid-cols-2">
                <div><label for="adapter" class="block text-sm font-medium text-gray-700">Adapter</label>
                    <select id="adapter" name="adapter" class="w-full rounded-md border border-gray-300 px-3 py-2">@foreach ($adapters as $a)<option value="{{ $a }}">{{ $a }}</option>@endforeach</select></div>
                <div><label for="type" class="block text-sm font-medium text-gray-700">Type</label>
                    <select id="type" name="type" class="w-full rounded-md border border-gray-300 px-3 py-2">@foreach (['llm', 'voice', 'image', 'video', 'embedding'] as $t)<option value="{{ $t }}">{{ $t }}</option>@endforeach</select></div>
            </div>
            <x-field name="base_url" label="Base URL (Leave empty to use default; required for compatible adapters)" type="url" />
            <div class="grid gap-3 sm:grid-cols-2">
                <x-field name="timeout_ms" type="number" label="Timeout (ms)" value="60000" required />
                <x-field name="priority" type="number" label="Priority (smaller = first)" value="100" required />
            </div>
            <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="allow_private" value="1"> Allow local/private address (Ollama/compatible only)</label>
            <x-button>Create (disabled)</x-button>
        </form>
    </section>
</x-layouts.admin>
