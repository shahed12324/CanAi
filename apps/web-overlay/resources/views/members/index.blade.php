<x-layouts.app title="Members">
    {{-- JARVIS ONE: page-specific visual identity; existing actions preserved --}}
    <header class="jarvis-module-hero jarvis-accent-violet" data-jarvis-module="members" aria-label="Members">
      <div class="jarvis-module-hero__identity"><span class="jarvis-module-hero__glyph" aria-hidden="true">✦</span><div>
        <div class="jarvis-module-hero__eyebrow">JARVIS ONE <span aria-hidden="true">/</span> MEMBERS</div>
        <h1>Members</h1>
      </div></div>
      <span class="jarvis-module-hero__section">Members Workspace</span>
    </header>

    <h2 class="text-xl font-semibold">Members</h2>

    <div class="overflow-x-auto rounded-lg bg-white shadow">
        <table class="min-w-full text-sm">
            <thead class="bg-gray-50 text-left text-gray-600">
                <tr><th class="px-4 py-2">Name</th><th class="px-4 py-2">Email</th><th class="px-4 py-2">Role</th><th class="px-4 py-2"></th></tr>
            </thead>
            <tbody>
                @foreach ($members as $m)
                    <tr class="border-t">
                        <td class="px-4 py-2">{{ $m->name }}</td>
                        <td class="px-4 py-2">{{ $m->email }}</td>
                        <td class="px-4 py-2">{{ $roles[$m->id] ?? '—' }}</td>
                        <td class="px-4 py-2 text-right">
                            @if ($canRemove && $m->id !== $ownerId && $m->id !== auth()->id())
                                <form method="POST" action="{{ route('members.destroy', ['userId' => $m->id]) }}">
                                    @csrf
                                    @method('DELETE')
                                    <x-button variant="danger">Remove</x-button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    @if ($pending->count() > 0)
        <section class="rounded-lg bg-white p-5 shadow">
            <h3 class="mb-2 font-medium">Pending invitations</h3>
            <ul class="space-y-1 text-sm text-gray-700">
                @foreach ($pending as $p)
                    <li>{{ $p->email }} — {{ $p->role }}</li>
                @endforeach
            </ul>
        </section>
    @endif

    @if ($canInvite)
        <section class="max-w-md space-y-3 rounded-lg bg-white p-5 shadow">
            <h3 class="font-medium">Invite a member</h3>
            <form method="POST" action="{{ route('members.invite') }}" class="space-y-3">
                @csrf
                <x-field name="email" type="email" label="Email" required />
                <div class="space-y-1">
                    <label for="role" class="block text-sm font-medium text-gray-700">Role</label>
                    <select id="role" name="role" class="w-full rounded-md border border-gray-300 px-3 py-2">
                        @foreach ($invitable as $role)
                            <option value="{{ $role }}">{{ $role }}</option>
                        @endforeach
                    </select>
                    @error('role') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <x-button>Send invitation</x-button>
            </form>
        </section>
    @endif
</x-layouts.app>
