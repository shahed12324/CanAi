<x-layouts.admin title="Workspace" :crumbs="['Workspace' => null]">
    {{-- JARVIS ONE: page-specific visual identity; existing actions preserved --}}
    <header class="jarvis-module-hero jarvis-accent-violet" data-jarvis-module="admin" aria-label="Workspace">
      <div class="jarvis-module-hero__identity"><span class="jarvis-module-hero__glyph" aria-hidden="true">✦</span><div>
        <div class="jarvis-module-hero__eyebrow">JARVIS ONE <span aria-hidden="true">/</span> ADMIN</div>
        <h1>Workspace</h1><p>Manage your platform, permissions and operations.</p>
      </div></div>
      <span class="jarvis-module-hero__section">Admin Workspace</span>
    </header>
    <div class="flex flex-wrap items-center justify-between gap-3">
        <h1 class="text-xl font-semibold">Workspace</h1>
        <form method="GET" class="flex flex-wrap gap-2 text-sm">
            <input name="q" value="{{ request('q') }}" placeholder="Name, slug or owner email" class="rounded border border-gray-300 px-3 py-1.5">
            <select name="status" class="rounded border border-gray-300 px-2 py-1.5">
                <option value="">All statuses</option>
                @foreach (['active', 'suspended', 'closed'] as $s)<option value="{{ $s }}" @selected(request('status') === $s)>{{ $s }}</option>@endforeach
            </select>
            <select name="type" class="rounded border border-gray-300 px-2 py-1.5">
                <option value="">All types</option>
                @foreach (['personal', 'organization'] as $s)<option value="{{ $s }}" @selected(request('type') === $s)>{{ $s }}</option>@endforeach
            </select>
            <label class="flex items-center gap-1"><input type="checkbox" name="trashed" value="1" @checked(request()->boolean('trashed'))> Deleted</label>
            <x-button variant="ghost">Filter</x-button>
        </form>
    </div>
    <div class="overflow-x-auto rounded-lg bg-white shadow">
        <table class="min-w-full text-sm">
            <thead class="bg-gray-50 text-left text-gray-600"><tr><th class="px-4 py-2">Name</th><th class="px-4 py-2">Type</th><th class="px-4 py-2">Owner</th><th class="px-4 py-2">Plans</th><th class="px-4 py-2">Status</th></tr></thead>
            <tbody>
            @forelse ($tenants as $t)
                <tr class="border-t">
                    <td class="px-4 py-2"><a class="text-indigo-600" href="{{ route('admin.tenants.show', $t->id) }}">{{ $t->name }}</a> <span class="text-xs text-gray-500">{{ $t->slug }}</span></td>
                    <td class="px-4 py-2">{{ $t->type }}</td>
                    <td class="px-4 py-2">{{ $t->owner_email }}</td>
                    <td class="px-4 py-2">{{ $t->plan_name ?? 'Free' }}</td>
                    <td class="px-4 py-2">{{ $t->deleted_at ? 'Deleted' : $t->status }}</td>
                </tr>
            @empty
                <tr><td colspan="5" class="px-4 py-6 text-center text-gray-500">No workspaces.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    {{ $tenants->links() }}
</x-layouts.admin>
