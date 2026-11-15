<x-layouts.admin :title="$user->name" :crumbs="['Users' => route('admin.users.index'), $user->name => null]">
    {{-- JARVIS ONE: page-specific visual identity; existing actions preserved --}}
    <header class="jarvis-module-hero jarvis-accent-violet" data-jarvis-module="admin" aria-label="Admin details">
      <div class="jarvis-module-hero__identity"><span class="jarvis-module-hero__glyph" aria-hidden="true">✦</span><div>
        <div class="jarvis-module-hero__eyebrow">JARVIS ONE <span aria-hidden="true">/</span> ADMIN</div>
        <h1>Admin details</h1><p>Manage your platform, permissions and operations.</p>
      </div></div>
      <span class="jarvis-module-hero__section">Admin Workspace</span>
    </header>
    @php($manage = auth()->user()->can('users.manage'))
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold">{{ $user->name }}</h1>
            <p class="text-sm text-gray-600">{{ $user->email }} · Status: <strong>{{ $user->deleted_at ? 'Deleted' : $user->status }}</strong>
                @unless ($user->email_verified_at) · Email unverified @endunless
                @if ($user->two_factor_confirmed_at) · 2FA Active @endif</p>
        </div>
        @if ($manage)
            <div class="flex flex-wrap gap-2">
                @if ($user->deleted_at)
                    <form method="POST" action="{{ route('admin.users.restore', $user->id) }}">@csrf<x-button>Restore</x-button></form>
                @else
                    @if ($user->status === 'suspended')
                        <form method="POST" action="{{ route('admin.users.activate', $user->id) }}">@csrf<x-button>Activate</x-button></form>
                    @else
                        <form method="POST" action="{{ route('admin.users.suspend', $user->id) }}">@csrf<x-button variant="danger">Suspend</x-button></form>
                    @endif
                    <form method="POST" action="{{ route('admin.users.revoke', $user->id) }}">@csrf<x-button variant="ghost">Revoke all sessions/tokens ({{ $sessionCount }}/{{ $tokenCount }})</x-button></form>
                    <form method="POST" action="{{ route('admin.users.reset', $user->id) }}">@csrf<x-button variant="ghost">Send reset link</x-button></form>
                    <form method="POST" action="{{ route('admin.users.destroy', $user->id) }}" onsubmit="return confirm('Delete the user?')">@csrf @method('DELETE')<x-button variant="danger">Delete</x-button></form>
                @endif
            </div>
        @endif
    </div>

    @if ($manage && ! $user->deleted_at)
        <section class="max-w-md space-y-3 rounded-lg bg-white p-5 shadow">
            <h2 class="font-medium">Profile</h2>
            <form method="POST" action="{{ route('admin.users.update', $user->id) }}" class="space-y-3">
                @csrf @method('PUT')
                <x-field name="name" label="Name" :value="$user->name" required />
                <x-field name="timezone" label="Timezone" :value="$user->timezone" required />
                <div><label for="locale" class="block text-sm font-medium text-gray-700">Language</label>
                    <select id="locale" name="locale" class="w-full rounded-md border border-gray-300 px-3 py-2">
                        @foreach (['bn' => 'Bengali', 'en' => 'English'] as $v => $l)<option value="{{ $v }}" @selected($user->locale === $v)>{{ $l }}</option>@endforeach
                    </select></div>
                <x-button>Save</x-button>
            </form>
        </section>
    @endif

    @can('roles.manage')
        <section class="max-w-md space-y-3 rounded-lg bg-white p-5 shadow">
            <h2 class="font-medium">Platform roles</h2>
            <form method="POST" action="{{ route('admin.users.platform_role', $user->id) }}" class="space-y-3">
                @csrf @method('PUT')
                <select name="role" class="w-full rounded-md border border-gray-300 px-3 py-2">
                    <option value="">— Not an admin —</option>
                    @foreach ($platformRoles as $r)<option value="{{ $r }}" @selected($platformRole === $r)>{{ $r }}</option>@endforeach
                </select>
                <x-button>Save roles</x-button>
            </form>
        </section>
    @endcan

    <section class="rounded-lg bg-white p-5 shadow">
        <h2 class="mb-2 font-medium">Workspace Memberships</h2>
        @forelse ($memberships as $m)
            <p class="flex justify-between border-t py-1 text-sm"><a class="text-indigo-600" href="{{ route('admin.tenants.show', $m->id) }}">{{ $m->name }}</a><span class="text-gray-500">{{ $m->type }} · {{ $m->status }}</span></p>
        @empty
            <p class="text-sm text-gray-500">No memberships.</p>
        @endforelse
    </section>

    <section class="rounded-lg bg-white p-5 shadow">
        <h2 class="mb-2 font-medium">Recent logins</h2>
        @forelse ($logins as $l)
            <p class="flex flex-wrap justify-between gap-2 border-t py-1 text-sm"><span>{{ $l->result }} · {{ $l->ip }}</span><span class="text-gray-500">{{ \Illuminate\Support\Carbon::parse($l->created_at)->diffForHumans() }}</span></p>
        @empty
            <p class="text-sm text-gray-500">No logs.</p>
        @endforelse
    </section>
</x-layouts.admin>
