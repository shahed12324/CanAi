<x-layouts.admin title="Plans" :crumbs="['Plans' => null]">
    {{-- JARVIS ONE: page-specific visual identity; existing actions preserved --}}
    <header class="jarvis-module-hero jarvis-accent-violet" data-jarvis-module="admin" aria-label="Plans">
      <div class="jarvis-module-hero__identity"><span class="jarvis-module-hero__glyph" aria-hidden="true">✦</span><div>
        <div class="jarvis-module-hero__eyebrow">JARVIS ONE <span aria-hidden="true">/</span> ADMIN</div>
        <h1>Plans</h1><p>Manage your platform, permissions and operations.</p>
      </div></div>
      <span class="jarvis-module-hero__section">Admin Workspace</span>
    </header>
    <h1 class="text-xl font-semibold">Plans</h1>
    <div class="overflow-x-auto rounded-lg bg-white shadow">
        <table class="min-w-full text-sm">
            <thead class="bg-gray-50 text-left text-gray-600"><tr><th class="px-4 py-2">Name</th><th class="px-4 py-2">Code</th><th class="px-4 py-2">Status</th><th class="px-4 py-2">Public</th><th class="px-4 py-2">Subscribers</th></tr></thead>
            <tbody>
            @foreach ($plans as $p)
                <tr class="border-t">
                    <td class="px-4 py-2"><a class="text-indigo-600" href="{{ route('admin.plans.show', $p->id) }}">{{ $p->name }}</a>@if ($p->is_lifetime_free) <span class="text-xs text-gray-500">(lifetime free)</span>@endif</td>
                    <td class="px-4 py-2">{{ $p->code }}</td><td class="px-4 py-2">{{ $p->status }}</td>
                    <td class="px-4 py-2">{{ $p->is_public ? 'Yes' : 'No' }}</td><td class="px-4 py-2">{{ $p->subscribers }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
    <section class="max-w-md space-y-3 rounded-lg bg-white p-5 shadow">
        <h2 class="font-medium">New plan</h2>
        <form method="POST" action="{{ route('admin.plans.store') }}" class="space-y-3">
            @csrf
            <x-field name="code" label="Code (lowercase English, for example starter)" required />
            <x-field name="name" label="Name" required />
            <x-button>Create</x-button>
        </form>
    </section>
</x-layouts.admin>
