<x-layouts.admin title="AI Models" :crumbs="['AI Models' => null]">
    {{-- JARVIS ONE: page-specific visual identity; existing actions preserved --}}
    <header class="jarvis-module-hero jarvis-accent-violet" data-jarvis-module="admin" aria-label="AI Models">
      <div class="jarvis-module-hero__identity"><span class="jarvis-module-hero__glyph" aria-hidden="true">✦</span><div>
        <div class="jarvis-module-hero__eyebrow">JARVIS ONE <span aria-hidden="true">/</span> ADMIN</div>
        <h1>AI Models</h1><p>Manage your platform, permissions and operations.</p>
      </div></div>
      <span class="jarvis-module-hero__section">Admin Workspace</span>
    </header>
    <h1 class="text-xl font-semibold">AI models</h1>
    <p class="text-sm text-gray-600">A new model starts hidden and unpriced. Click a model to open its provider page, where you set capabilities, price and which plans may use it, then set it to active.</p>
    <div class="overflow-x-auto rounded-lg bg-white shadow">
        <table class="min-w-full text-sm">
            <thead class="bg-gray-50 text-left text-gray-600"><tr><th class="px-4 py-2">Model</th><th class="px-4 py-2">Key</th><th class="px-4 py-2">Provider</th><th class="px-4 py-2">Category</th><th class="px-4 py-2">Status</th></tr></thead>
            <tbody>
            @forelse ($models as $m)
                <tr class="border-t">
                    <td class="px-4 py-2"><a class="text-indigo-600" href="{{ route('admin.providers.show', $m->provider_id) }}">{{ $m->display_name }}</a></td>
                    <td class="px-4 py-2 font-mono text-xs">{{ $m->model_key }}</td>
                    <td class="px-4 py-2">{{ $m->provider }}</td>
                    <td class="px-4 py-2">{{ $m->category }}</td>
                    <td class="px-4 py-2">{{ $m->status }}</td>
                </tr>
            @empty
                <tr><td colspan="5" class="px-4 py-6 text-center text-gray-500">No models yet.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <section class="max-w-md space-y-3 rounded-lg bg-white p-5 shadow">
        <h2 class="font-medium">Add a model</h2>
        @if ($providers->isEmpty())
            <p class="text-sm text-gray-600">Create a provider first: <a class="text-indigo-600" href="{{ route('admin.providers.index') }}">AI Provider</a>.</p>
        @else
            <form method="POST" id="model-form" action="{{ route('admin.models.store', $providers[0]->id) }}" class="space-y-3">
                @csrf
                <div><label for="prov" class="block text-sm font-medium text-gray-700">Provider</label>
                    <select id="prov" class="w-full rounded-md border border-gray-300 px-3 py-2">@foreach ($providers as $p)<option value="{{ route('admin.models.store', $p->id) }}">{{ $p->name }} ({{ $p->type }})</option>@endforeach</select></div>
                <x-field name="model_key" label="Model key (as the provider knows it, e.g. gpt-4o)" required />
                <x-field name="display_name" label="Display name" required />
                <div><label for="category_new" class="block text-sm font-medium text-gray-700">Category</label>
                    <select id="category_new" name="category" class="w-full rounded-md border border-gray-300 px-3 py-2">@foreach ($categories as $t)<option value="{{ $t }}">{{ $t }}</option>@endforeach</select></div>
                <x-button>Add model</x-button>
            </form>
            <script>document.getElementById('prov').addEventListener('change', function () { document.getElementById('model-form').action = this.value; });</script>
        @endif
    </section>
</x-layouts.admin>
