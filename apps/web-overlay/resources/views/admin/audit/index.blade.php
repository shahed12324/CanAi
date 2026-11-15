<x-layouts.admin title="Audit log" :crumbs="['Audit log' => null]">
    {{-- JARVIS ONE: page-specific visual identity; existing actions preserved --}}
    <header class="jarvis-module-hero jarvis-accent-violet" data-jarvis-module="admin" aria-label="Audit log">
      <div class="jarvis-module-hero__identity"><span class="jarvis-module-hero__glyph" aria-hidden="true">✦</span><div>
        <div class="jarvis-module-hero__eyebrow">JARVIS ONE <span aria-hidden="true">/</span> ADMIN</div>
        <h1>Audit log</h1><p>Manage your platform, permissions and operations.</p>
      </div></div>
      <span class="jarvis-module-hero__section">Admin Workspace</span>
    </header>
    <h1 class="text-xl font-semibold">Audit log</h1>
    <form method="GET" class="flex flex-wrap gap-2 rounded-lg bg-white p-4 text-sm shadow">
        <input name="action" value="{{ request('action') }}" placeholder="Action" class="rounded border border-gray-300 px-2 py-1.5">
        <input name="actor" value="{{ request('actor') }}" placeholder="Actor email/ID" class="rounded border border-gray-300 px-2 py-1.5">
        <input name="tenant" value="{{ request('tenant') }}" placeholder="Workspace Name/ID" class="rounded border border-gray-300 px-2 py-1.5">
        <select name="channel" class="rounded border border-gray-300 px-2 py-1.5">
            <option value="">All channels</option>
            @foreach (['admin', 'cli', 'web', 'api'] as $c)<option value="{{ $c }}" @selected(request('channel') === $c)>{{ $c }}</option>@endforeach
        </select>
        <input type="date" name="from" value="{{ request('from') }}" class="rounded border border-gray-300 px-2 py-1.5" aria-label="Start">
        <input type="date" name="to" value="{{ request('to') }}" class="rounded border border-gray-300 px-2 py-1.5" aria-label="End">
        <x-button variant="ghost">Filter</x-button>
    </form>
    <div class="overflow-x-auto rounded-lg bg-white shadow">
        <table class="min-w-full text-sm">
            <thead class="bg-gray-50 text-left text-gray-600"><tr><th class="px-4 py-2">Period</th><th class="px-4 py-2">Actor</th><th class="px-4 py-2">Action</th><th class="px-4 py-2">Subject</th><th class="px-4 py-2">Workspace</th><th class="px-4 py-2">Description</th></tr></thead>
            <tbody>
            @forelse ($logs as $l)
                <tr class="border-t align-top">
                    <td class="whitespace-nowrap px-4 py-2 text-gray-500">{{ \Illuminate\Support\Carbon::parse($l->created_at)->format('Y-m-d H:i:s') }}</td>
                    <td class="px-4 py-2">{{ $l->actor_email ?? 'System' }}<br><span class="text-xs text-gray-500">{{ $l->channel }} · {{ $l->ip }}</span></td>
                    <td class="px-4 py-2">{{ $l->action }}</td>
                    <td class="px-4 py-2">{{ $l->subject_type }} {{ $l->subject_id }}</td>
                    <td class="px-4 py-2">{{ $l->tenant_name }}</td>
                    <td class="px-4 py-2">
                        @if ($l->before || $l->after)
                            <details><summary class="cursor-pointer text-indigo-600">View</summary>
                                <pre class="mt-1 max-w-md overflow-x-auto rounded bg-gray-50 p-2 text-xs">Before: {{ $l->before }}
After: {{ $l->after }}</pre>
                            </details>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="px-4 py-6 text-center text-gray-500">No records.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    {{ $logs->links() }}
</x-layouts.admin>
