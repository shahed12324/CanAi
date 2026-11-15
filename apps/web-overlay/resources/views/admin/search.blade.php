<x-layouts.admin title="Search" :crumbs="['Search' => null]">
    {{-- JARVIS ONE: page-specific visual identity; existing actions preserved --}}
    <header class="jarvis-module-hero jarvis-accent-violet" data-jarvis-module="admin" aria-label="Search">
      <div class="jarvis-module-hero__identity"><span class="jarvis-module-hero__glyph" aria-hidden="true">✦</span><div>
        <div class="jarvis-module-hero__eyebrow">JARVIS ONE <span aria-hidden="true">/</span> ADMIN</div>
        <h1>Search</h1><p>Manage your platform, permissions and operations.</p>
      </div></div>
      <span class="jarvis-module-hero__section">Admin Workspace</span>
    </header>
    <h1 class="text-xl font-semibold">“{{ $q }}”-results for</h1>
    @if (mb_strlen($q) < 2)<p class="text-sm text-gray-600">Type at least 2 characters.</p>@endif
    @if ($users->count())
        <section class="rounded-lg bg-white p-4 shadow"><h2 class="mb-2 font-medium">Users</h2>
            @foreach ($users as $u)<p class="border-t py-1 text-sm"><a class="text-indigo-600" href="{{ route('admin.users.show', $u->id) }}">{{ $u->name }}</a> — {{ $u->email }}</p>@endforeach
        </section>
    @endif
    @if ($tenants->count())
        <section class="rounded-lg bg-white p-4 shadow"><h2 class="mb-2 font-medium">Workspace</h2>
            @foreach ($tenants as $t)<p class="border-t py-1 text-sm"><a class="text-indigo-600" href="{{ route('admin.tenants.show', $t->id) }}">{{ $t->name }}</a> ({{ $t->type }})</p>@endforeach
        </section>
    @endif
    @if (mb_strlen($q) >= 2 && ! $users->count() && ! $tenants->count())<p class="text-sm text-gray-600">Nothing found.</p>@endif
</x-layouts.admin>
