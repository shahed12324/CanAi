<x-layouts.admin title="Users" :crumbs="['Users' => null]">
    {{-- JARVIS ONE: page-specific visual identity; existing actions preserved --}}
    <header class="jarvis-module-hero jarvis-accent-violet" data-jarvis-module="admin" aria-label="Users">
      <div class="jarvis-module-hero__identity"><span class="jarvis-module-hero__glyph" aria-hidden="true">✦</span><div>
        <div class="jarvis-module-hero__eyebrow">JARVIS ONE <span aria-hidden="true">/</span> ADMIN</div>
        <h1>Users</h1><p>Manage your platform, permissions and operations.</p>
      </div></div>
      <span class="jarvis-module-hero__section">Admin Workspace</span>
    </header>
    <div class="flex flex-wrap items-center justify-between gap-3">
        <h1 class="text-xl font-semibold">Users</h1>
        <form method="GET" class="flex flex-wrap gap-2 text-sm">
            <input name="q" value="{{ request('q') }}" placeholder="Name or email" class="rounded border border-gray-300 px-3 py-1.5">
            <select name="status" class="rounded border border-gray-300 px-2 py-1.5">
                <option value="">All statuses</option>
                @foreach (['active' => 'Active', 'suspended' => 'Suspended', 'pending' => 'Pending'] as $v => $l)<option value="{{ $v }}" @selected(request('status') === $v)>{{ $l }}</option>@endforeach
            </select>
            <label class="flex items-center gap-1"><input type="checkbox" name="trashed" value="1" @checked(request()->boolean('trashed'))> Deleted</label>
            <x-button variant="ghost">Filter</x-button>
        </form>
    </div>
    <div class="overflow-x-auto rounded-lg bg-white shadow">
        <table class="min-w-full text-sm">
            <thead class="bg-gray-50 text-left text-gray-600"><tr><th class="px-4 py-2">Name</th><th class="px-4 py-2">Email</th><th class="px-4 py-2">Status</th><th class="px-4 py-2">Joined</th></tr></thead>
            <tbody>
            @forelse ($users as $u)
                <tr class="border-t">
                    <td class="px-4 py-2"><a class="text-indigo-600" href="{{ route('admin.users.show', $u->id) }}">{{ $u->name }}</a>@if ($u->is_platform_admin) <span class="ml-1 rounded bg-indigo-50 px-1 text-xs text-indigo-700">admin</span>@endif</td>
                    <td class="px-4 py-2">{{ $u->email }}@unless ($u->email_verified_at) <span class="text-xs text-yellow-700">(Unverified)</span>@endunless</td>
                    <td class="px-4 py-2">{{ $u->deleted_at ? 'Deleted' : $u->status }}</td>
                    <td class="px-4 py-2 text-gray-500">{{ \Illuminate\Support\Carbon::parse($u->created_at)->format('Y-m-d') }}</td>
                </tr>
            @empty
                <tr><td colspan="4" class="px-4 py-6 text-center text-gray-500">No users.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    {{ $users->links() }}

    @can('users.manage')
        <section class="max-w-md space-y-3 rounded-lg bg-white p-5 shadow">
            <h2 class="font-medium">New user</h2>
            <p class="text-xs text-gray-500">The user will set their own password; a reset link will be emailed to them.</p>
            <form method="POST" action="{{ route('admin.users.store') }}" class="space-y-3">
                @csrf
                <x-field name="name" label="Name" required />
                <x-field name="email" type="email" label="Email" required />
                <div><label for="locale" class="block text-sm font-medium text-gray-700">Language</label>
                    <select id="locale" name="locale" class="w-full rounded-md border border-gray-300 px-3 py-2"><option value="bn">Bengali</option><option value="en">English</option></select></div>
                <x-button>Create</x-button>
            </form>
        </section>
    @endcan
</x-layouts.admin>
