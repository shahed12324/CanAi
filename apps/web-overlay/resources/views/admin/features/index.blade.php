<x-layouts.admin title="Features" :crumbs="['Features' => null]">
    {{-- JARVIS ONE: page-specific visual identity; existing actions preserved --}}
    <header class="jarvis-module-hero jarvis-accent-violet" data-jarvis-module="admin" aria-label="Features">
      <div class="jarvis-module-hero__identity"><span class="jarvis-module-hero__glyph" aria-hidden="true">✦</span><div>
        <div class="jarvis-module-hero__eyebrow">JARVIS ONE <span aria-hidden="true">/</span> ADMIN</div>
        <h1>Features</h1><p>Manage your platform, permissions and operations.</p>
      </div></div>
      <span class="jarvis-module-hero__section">Admin Workspace</span>
    </header>
    <h1 class="text-xl font-semibold">Feature catalog</h1>
    <div class="overflow-x-auto rounded-lg bg-white shadow">
        <table class="min-w-full text-sm">
            <thead class="bg-gray-50 text-left text-gray-600"><tr><th class="px-4 py-2">Key</th><th class="px-4 py-2">Type</th><th class="px-4 py-2">Category and description</th><th class="px-4 py-2">Plans</th><th class="px-4 py-2"></th></tr></thead>
            <tbody>
            @foreach ($features as $f)
                <tr class="border-t align-top">
                    <td class="px-4 py-2">{{ $f->key }}</td><td class="px-4 py-2">{{ $f->type }}</td>
                    <td class="px-4 py-2">
                        <form method="POST" action="{{ route('admin.features.update', $f->key) }}" class="flex flex-wrap gap-2">
                            @csrf @method('PUT')
                            <input name="category" value="{{ $f->category }}" required maxlength="50" class="w-28 rounded border border-gray-300 px-2 py-1" aria-label="Category">
                            <input name="description" value="{{ $f->description }}" maxlength="500" class="w-64 rounded border border-gray-300 px-2 py-1" aria-label="Description">
                            <x-button variant="ghost">Save</x-button>
                        </form>
                    </td>
                    <td class="px-4 py-2">{{ $f->plans }}</td>
                    <td class="px-4 py-2"><form method="POST" action="{{ route('admin.features.destroy', $f->key) }}" onsubmit="return confirm('Delete the feature?')">@csrf @method('DELETE')<x-button variant="danger">Delete</x-button></form></td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
    <section class="max-w-md space-y-3 rounded-lg bg-white p-5 shadow">
        <h2 class="font-medium">New feature</h2>
        <form method="POST" action="{{ route('admin.features.store') }}" class="space-y-3">
            @csrf
            <x-field name="key" label="Key (for example module.crm)" required />
            <x-field name="category" label="Category" required />
            <div><label for="type" class="block text-sm font-medium text-gray-700">Type</label>
                <select id="type" name="type" class="w-full rounded-md border border-gray-300 px-3 py-2"><option value="boolean">boolean</option><option value="limit">limit</option><option value="meter">meter</option></select></div>
            <x-field name="description" label="Description" />
            <x-button>Add</x-button>
        </form>
    </section>
</x-layouts.admin>
