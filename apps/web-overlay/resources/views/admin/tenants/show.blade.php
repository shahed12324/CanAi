<x-layouts.admin :title="$tenant->name" :crumbs="['Workspace' => route('admin.tenants.index'), $tenant->name => null]">
    {{-- JARVIS ONE: page-specific visual identity; existing actions preserved --}}
    <header class="jarvis-module-hero jarvis-accent-violet" data-jarvis-module="admin" aria-label="Admin details">
      <div class="jarvis-module-hero__identity"><span class="jarvis-module-hero__glyph" aria-hidden="true">✦</span><div>
        <div class="jarvis-module-hero__eyebrow">JARVIS ONE <span aria-hidden="true">/</span> ADMIN</div>
        <h1>Admin details</h1><p>Manage your platform, permissions and operations.</p>
      </div></div>
      <span class="jarvis-module-hero__section">Admin Workspace</span>
    </header>
    @php($manage = auth()->user()->can('tenants.manage'))
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold">{{ $tenant->name }}</h1>
            <p class="text-sm text-gray-600">{{ $tenant->type }} · Status: <strong>{{ $tenant->deleted_at ? 'Deleted' : $tenant->status }}</strong>
                @if ($owner) · Owner: <a class="text-indigo-600" href="{{ route('admin.users.show', $owner->id) }}">{{ $owner->email }}</a>@endif</p>
        </div>
        @if ($manage)
            <div class="flex flex-wrap gap-2">
                @if ($tenant->deleted_at)
                    <form method="POST" action="{{ route('admin.tenants.restore', $tenant->id) }}">@csrf<x-button>Restore</x-button></form>
                @else
                    @if ($tenant->status === 'suspended')
                        <form method="POST" action="{{ route('admin.tenants.activate', $tenant->id) }}">@csrf<x-button>Activate</x-button></form>
                    @else
                        <form method="POST" action="{{ route('admin.tenants.suspend', $tenant->id) }}">@csrf<x-button variant="danger">Suspend</x-button></form>
                    @endif
                    <form method="POST" action="{{ route('admin.tenants.destroy', $tenant->id) }}" onsubmit="return confirm('Workspace Delete?')">@csrf @method('DELETE')<x-button variant="danger">Delete</x-button></form>
                @endif
            </div>
        @endif
    </div>

    <section class="rounded-lg bg-white p-5 shadow">
        <h2 class="mb-2 font-medium">Subscription</h2>
        @if ($subscription)
            <p class="text-sm">Plan: <strong>{{ $subscription->plan_name }}</strong> · {{ $subscription->status }}
                @if ($subscription->interval) · {{ $subscription->interval }}@endif
                @if ($subscription->current_period_end) · Expires: {{ \Illuminate\Support\Carbon::parse($subscription->current_period_end)->format('Y-m-d') }}@endif</p>
        @else
            <p class="text-sm text-gray-600">No subscription — Free plan applies.</p>
        @endif
        @if ($manage && ! $tenant->deleted_at)
            <form method="POST" action="{{ route('admin.tenants.plan', $tenant->id) }}" class="mt-3 grid max-w-xl gap-3 sm:grid-cols-2">
                @csrf
                <select name="plan_id" class="rounded-md border border-gray-300 px-3 py-2 text-sm" aria-label="Plans">
                    @foreach ($plans as $p)<option value="{{ $p->id }}">{{ $p->name }} ({{ $p->code }})</option>@endforeach
                </select>
                <select name="status" class="rounded-md border border-gray-300 px-3 py-2 text-sm" aria-label="Status"><option value="active">active</option><option value="trialing">trialing</option></select>
                <select name="interval" class="rounded-md border border-gray-300 px-3 py-2 text-sm" aria-label="Interval"><option value="">—</option><option value="month">month</option><option value="year">year</option></select>
                <input type="date" name="period_end" class="rounded-md border border-gray-300 px-3 py-2 text-sm" aria-label="Expired">
                <div class="sm:col-span-2"><x-button>Change plan</x-button></div>
            </form>
        @endif
    </section>

    <section class="rounded-lg bg-white p-5 shadow">
        <h2 class="mb-2 font-medium">Credit wallet</h2>
        @php($fmt = fn ($v) => \App\Credits\CreditMath::format($v))
        @if ($wallet)
            <p class="text-sm">Balance: <strong>{{ $fmt($wallet->balance) }}</strong> · Held: {{ $fmt($wallet->reserved) }}
                @if ($wallet->spending_limit_monthly !== null) · Monthly limit: {{ $fmt($wallet->spending_limit_monthly) }}@endif</p>
        @else
            <p class="text-sm text-gray-500">No wallet created yet.</p>
        @endif
        @foreach ($creditTx as $t)
            <div class="flex justify-between border-t py-1 text-sm"><span>{{ $t->type }} <span class="text-gray-500">{{ $t->note }}</span></span><span class="tabular-nums">{{ $fmt($t->amount) }} → {{ $fmt($t->balance_after) }}</span></div>
        @endforeach
        @if (auth()->user()->can('credits.manage') && ! $tenant->deleted_at)
            <form method="POST" action="{{ route('admin.tenants.credits.adjust', $tenant->id) }}" class="mt-3 grid max-w-xl gap-3 sm:grid-cols-2">
                @csrf
                <input type="hidden" name="key" value="{{ $adjustKey }}">
                <select name="type" class="rounded-md border border-gray-300 px-3 py-2 text-sm" aria-label="Type"><option value="adjustment">Adjustment (+/−)</option><option value="bonus">Bonus (+)</option></select>
                <input name="amount" inputmode="decimal" value="{{ old('amount') }}" required placeholder="Amount, for example 50 or -20" class="rounded-md border border-gray-300 px-3 py-2 text-sm" aria-label="Quantity">
                <input name="reason" value="{{ old('reason') }}" required minlength="3" maxlength="500" placeholder="Reason (goes to audit)" class="rounded-md border border-gray-300 px-3 py-2 text-sm sm:col-span-2" aria-label="Reason">
                <div class="sm:col-span-2"><x-button>Apply</x-button></div>
            </form>
        @endif
    </section>

    <section class="rounded-lg bg-white p-5 shadow">
        <h2 class="mb-2 font-medium">Feature override</h2>
        @forelse ($overrides as $o)
            <div class="flex flex-wrap items-center justify-between gap-2 border-t py-1 text-sm">
                <span>{{ $o->feature_key }} — {{ $o->enabled ? 'Active' : 'Closed' }}@if ($o->limit_value !== null) · Limit {{ $o->limit_value }}@endif <span class="text-gray-500">({{ $o->reason }})</span></span>
                @if ($manage)<form method="POST" action="{{ route('admin.tenants.overrides.delete', [$tenant->id, $o->feature_key]) }}">@csrf @method('DELETE')<x-button variant="ghost">Remove</x-button></form>@endif
            </div>
        @empty
            <p class="text-sm text-gray-500">No overrides.</p>
        @endforelse
        @if ($manage && ! $tenant->deleted_at)
            <form method="POST" action="{{ route('admin.tenants.overrides.save', $tenant->id) }}" class="mt-3 grid max-w-xl gap-3 sm:grid-cols-2">
                @csrf
                <select name="feature_key" class="rounded-md border border-gray-300 px-3 py-2 text-sm" aria-label="Features">
                    @foreach ($features as $f)<option value="{{ $f->key }}">{{ $f->key }}</option>@endforeach
                </select>
                <select name="enabled" class="rounded-md border border-gray-300 px-3 py-2 text-sm" aria-label="On/Off"><option value="1">Active</option><option value="0">Closed</option></select>
                <input name="limit_value" type="number" min="0" step="any" placeholder="Limit (optional)" class="rounded-md border border-gray-300 px-3 py-2 text-sm">
                <input name="reason" required minlength="3" maxlength="500" placeholder="Reason (required)" class="rounded-md border border-gray-300 px-3 py-2 text-sm">
                <div class="sm:col-span-2"><x-button>Override Save</x-button></div>
            </form>
        @endif
    </section>

    <section class="rounded-lg bg-white p-5 shadow">
        <h2 class="mb-2 font-medium">Members ({{ $members->count() }})</h2>
        @forelse ($members as $m)
            <p class="flex justify-between border-t py-1 text-sm"><a class="text-indigo-600" href="{{ route('admin.users.show', $m->id) }}">{{ $m->name }}</a><span class="text-gray-500">{{ $m->email }} · {{ $m->status }}</span></p>
        @empty
            <p class="text-sm text-gray-500">No members.</p>
        @endforelse
    </section>

    <section class="rounded-lg bg-white p-5 shadow">
        <h2 class="mb-2 font-medium">Recent audit</h2>
        @forelse ($audit as $a)
            <p class="flex justify-between gap-3 border-t py-1 text-sm"><span class="truncate">{{ $a->action }}</span><span class="shrink-0 text-gray-500">{{ \Illuminate\Support\Carbon::parse($a->created_at)->diffForHumans() }}</span></p>
        @empty
            <p class="text-sm text-gray-500">Nothing yet.</p>
        @endforelse
    </section>
</x-layouts.admin>
