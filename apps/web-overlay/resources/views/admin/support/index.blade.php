<x-layouts.admin title="Support tickets" :crumbs="['Support tickets' => null]">
    {{-- JARVIS ONE: page-specific visual identity; existing actions preserved --}}
    <header class="jarvis-module-hero jarvis-accent-violet" data-jarvis-module="admin" aria-label="Support tickets">
      <div class="jarvis-module-hero__identity"><span class="jarvis-module-hero__glyph" aria-hidden="true">✦</span><div>
        <div class="jarvis-module-hero__eyebrow">JARVIS ONE <span aria-hidden="true">/</span> ADMIN</div>
        <h1>Support tickets</h1><p>Manage your platform, permissions and operations.</p>
      </div></div>
      <span class="jarvis-module-hero__section">Admin Workspace</span>
    </header>
    <div class="flex flex-wrap items-center justify-between gap-3">
        <h1 class="text-xl font-semibold">Support tickets</h1>
        <form method="GET" class="flex flex-wrap gap-2 text-sm">
            <input name="q" value="{{ request('q') }}" placeholder="Subject, shop or email" class="rounded border border-gray-300 px-3 py-1.5">
            <select name="status" class="rounded border border-gray-300 px-2 py-1.5">
                <option value="all" @selected($status === 'all')>All</option>
                @foreach (['open', 'pending', 'solved', 'closed'] as $s)<option value="{{ $s }}" @selected($status === $s)>{{ ucfirst($s) }} ({{ $counts[$s] ?? 0 }})</option>@endforeach
            </select>
            <select name="priority" class="rounded border border-gray-300 px-2 py-1.5">
                <option value="">Any priority</option>
                @foreach (['high', 'normal', 'low'] as $s)<option value="{{ $s }}" @selected(request('priority') === $s)>{{ ucfirst($s) }}</option>@endforeach
            </select>
            <x-button variant="ghost">Filter</x-button>
        </form>
    </div>
    <div class="overflow-x-auto rounded-lg bg-white shadow">
        <table class="min-w-full text-sm">
            <thead class="bg-gray-50 text-left text-gray-600"><tr><th class="px-4 py-2">Subject</th><th class="px-4 py-2">Shop</th><th class="px-4 py-2">Priority</th><th class="px-4 py-2">Status</th><th class="px-4 py-2">Last activity</th></tr></thead>
            <tbody>
            @forelse ($tickets as $t)
                <tr class="border-t">
                    <td class="px-4 py-2"><a class="text-indigo-600" href="{{ route('admin.support.show', $t->id) }}">{{ $t->subject }}</a> <span class="text-xs text-gray-500">{{ $t->category }}</span></td>
                    <td class="px-4 py-2">{{ $t->shop }}<br><span class="text-xs text-gray-500">{{ $t->email }}</span></td>
                    <td class="px-4 py-2">{{ ucfirst($t->priority) }}</td>
                    <td class="px-4 py-2">{{ ucfirst($t->status) }}</td>
                    <td class="px-4 py-2 text-gray-500">{{ \Illuminate\Support\Carbon::parse($t->last_message_at)->diffForHumans() }}</td>
                </tr>
            @empty
                <tr><td colspan="5" class="px-4 py-6 text-center text-gray-500">No tickets.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    {{ $tickets->links() }}
</x-layouts.admin>
