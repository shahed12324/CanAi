<x-layouts.admin title="Dashboard">
    {{-- JARVIS ONE: page-specific visual identity; existing actions preserved --}}
    <header class="jarvis-module-hero jarvis-accent-violet" data-jarvis-module="admin" aria-label="Dashboard">
      <div class="jarvis-module-hero__identity"><span class="jarvis-module-hero__glyph" aria-hidden="true">✦</span><div>
        <div class="jarvis-module-hero__eyebrow">JARVIS ONE <span aria-hidden="true">/</span> ADMIN</div>
        <h1>Dashboard</h1><p>Manage your platform, permissions and operations.</p>
      </div></div>
      <span class="jarvis-module-hero__section">Admin Workspace</span>
    </header>

    <h1 class="text-xl font-semibold">Dashboard</h1>
    <div class="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6">
        @foreach ([
            ['Users', $users], ['New in last 7 days', $newUsers], ['Suspended users', $suspendedUsers],
            ['Organization workspaces', $tenants], ['Suspended workspaces', $suspendedTenants], ['Failed logins (24 hours)', $failedLogins],
        ] as [$label, $value])
            <div class="rounded-lg bg-white p-4 shadow"><p class="text-xs text-gray-500">{{ $label }}</p><p class="text-2xl font-semibold">{{ number_format($value) }}</p></div>
        @endforeach
    </div>
    <div class="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6">
        @foreach ([
            ['Total shops', $shopsTotal], ['Active shops', $shopsActive], ['Inactive shops', $shopsInactive],
            ['Shop members (staff)', $staffTotal], ['Running trials', $trialsRunning], ['Open support tickets', $openTickets],
        ] as [$label, $value])
            <div class="rounded-lg bg-white p-4 shadow"><p class="text-xs text-gray-500">{{ $label }}</p><p class="text-2xl font-semibold">{{ number_format($value) }}</p></div>
        @endforeach
    </div>
    <div class="rounded-lg bg-white p-4 shadow">
        <h2 class="mb-1 font-medium">Revenue this month (after refunds)</h2>
        @forelse ($revenueMonth as $r)<p class="text-lg font-semibold tabular-nums">{{ trim($r->currency) }} {{ number_format($r->minor / 100, 2) }}</p>@empty<p class="text-sm text-gray-500">No paid orders yet this month.</p>@endforelse
        <a class="text-sm text-indigo-600" href="{{ route('admin.billing.index') }}">Full revenue report →</a>
    </div>
    <div class="grid gap-4 lg:grid-cols-2">
        <section class="rounded-lg bg-white p-4 shadow">
            <h2 class="mb-2 font-medium">Active subscriptions by plan</h2>
            @forelse ($byPlan as $row)
                <p class="flex justify-between border-t py-1 text-sm"><span>{{ $row->name }}</span><span>{{ $row->total }}</span></p>
            @empty
                <p class="text-sm text-gray-500">No subscriptions (everyone is on the Free plan).</p>
            @endforelse
        </section>
        <section class="rounded-lg bg-white p-4 shadow">
            <h2 class="mb-2 font-medium">Recent audit</h2>
            @forelse ($recent as $r)
                <p class="flex justify-between gap-3 border-t py-1 text-sm"><span class="truncate">{{ $r->action }}</span><span class="shrink-0 text-gray-500">{{ \Illuminate\Support\Carbon::parse($r->created_at)->diffForHumans() }}</span></p>
            @empty
                <p class="text-sm text-gray-500">Nothing yet.</p>
            @endforelse
        </section>
    </div>
</x-layouts.admin>
