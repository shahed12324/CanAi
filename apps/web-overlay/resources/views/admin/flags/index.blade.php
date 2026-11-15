<x-layouts.admin title="Feature flags" :crumbs="['Feature flags' => null]">
    {{-- JARVIS ONE: page-specific visual identity; existing actions preserved --}}
    <header class="jarvis-module-hero jarvis-accent-violet" data-jarvis-module="admin" aria-label="Feature flags">
      <div class="jarvis-module-hero__identity"><span class="jarvis-module-hero__glyph" aria-hidden="true">✦</span><div>
        <div class="jarvis-module-hero__eyebrow">JARVIS ONE <span aria-hidden="true">/</span> ADMIN</div>
        <h1>Feature flags</h1><p>Manage your platform, permissions and operations.</p>
      </div></div>
      <span class="jarvis-module-hero__section">Admin Workspace</span>
    </header>
    <h1 class="text-xl font-semibold">Feature flags</h1>
    <div class="space-y-2">
        @forelse ($flags as $f)
            <div class="rounded-lg bg-white p-4 shadow">
                <form method="POST" action="{{ route('admin.flags.update', $f->key) }}" class="flex flex-wrap items-center gap-3 text-sm">
                    @csrf @method('PUT')
                    <strong class="w-48 truncate">{{ $f->key }}</strong>
                    <label class="flex items-center gap-1"><input type="checkbox" name="enabled" value="1" @checked($f->enabled)> Active</label>
                    <input type="number" name="percentage" min="0" max="100" value="{{ $f->percentage }}" placeholder="% (Empty = everyone)" class="w-36 rounded border border-gray-300 px-2 py-1" aria-label="Rollout percentage">
                    <input name="description" value="{{ $f->description }}" maxlength="500" placeholder="Description" class="min-w-0 flex-1 rounded border border-gray-300 px-2 py-1" aria-label="Description">
                    <x-button variant="ghost">Save</x-button>
                </form>
                <form method="POST" action="{{ route('admin.flags.destroy', $f->key) }}" class="mt-2" onsubmit="return confirm('Delete the flag?')">@csrf @method('DELETE')<x-button variant="danger">Delete</x-button></form>
            </div>
        @empty
            <p class="text-sm text-gray-500">No flags.</p>
        @endforelse
    </div>
    <section class="max-w-md space-y-3 rounded-lg bg-white p-5 shadow">
        <h2 class="font-medium">New flag</h2>
        <form method="POST" action="{{ route('admin.flags.store') }}" class="space-y-3">
            @csrf
            <x-field name="key" label="Key (for example beta.new_chat)" required />
            <x-field name="description" label="Description" />
            <x-button>Add (disabled)</x-button>
        </form>
    </section>
</x-layouts.admin>
