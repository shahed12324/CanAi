<x-layouts.admin title="Roles and permissions" :crumbs="['Roles and permissions' => null]">
    {{-- JARVIS ONE: page-specific visual identity; existing actions preserved --}}
    <header class="jarvis-module-hero jarvis-accent-violet" data-jarvis-module="admin" aria-label="Roles and permissions">
      <div class="jarvis-module-hero__identity"><span class="jarvis-module-hero__glyph" aria-hidden="true">✦</span><div>
        <div class="jarvis-module-hero__eyebrow">JARVIS ONE <span aria-hidden="true">/</span> ADMIN</div>
        <h1>Roles and permissions</h1><p>Manage your platform, permissions and operations.</p>
      </div></div>
      <span class="jarvis-module-hero__section">Admin Workspace</span>
    </header>
    <h1 class="text-xl font-semibold">Platform roles</h1>
    <p class="text-sm text-gray-600">Super Admin always gets all permissions and cannot be changed. Change the ticks of other roles and save.</p>
    @foreach ($platform as $name => $granted)
        <section class="rounded-lg bg-white p-5 shadow">
            <h2 class="mb-2 font-medium">{{ $name }}</h2>
            <form method="POST" action="{{ route('admin.roles.update', $name) }}">
                @csrf @method('PUT')
                <div class="grid gap-1 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($permissions as $p)
                        <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="permissions[]" value="{{ $p }}" @checked(in_array($p, $granted, true)) @disabled($name === 'Super Admin')> {{ $p }}</label>
                    @endforeach
                </div>
                @if ($name !== 'Super Admin')<div class="mt-3"><x-button>Save</x-button></div>@endif
            </form>
        </section>
    @endforeach

    <h2 class="pt-4 text-lg font-semibold">Workspace Role (view only)</h2>
    <div class="overflow-x-auto rounded-lg bg-white shadow">
        <table class="min-w-full text-sm">
            <thead class="bg-gray-50 text-left text-gray-600"><tr><th class="px-4 py-2">Permissions</th>@foreach ($tenantRoles as $role => $perms)<th class="px-4 py-2">{{ $role }}</th>@endforeach</tr></thead>
            <tbody>
            @foreach ($tenantPermissions as $p)
                <tr class="border-t"><td class="px-4 py-2">{{ $p }}</td>@foreach ($tenantRoles as $role => $perms)<td class="px-4 py-2">{{ in_array($p, $perms, true) ? '✓' : '—' }}</td>@endforeach</tr>
            @endforeach
            </tbody>
        </table>
    </div>
</x-layouts.admin>
