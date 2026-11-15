@php($fmt = fn ($v, $f = 'd M Y, H:i') => \Illuminate\Support\Carbon::parse($v)->setTimezone($tz)->format($f))
<x-layouts.app title="Tasks and follow-ups">
    {{-- JARVIS ONE: page-specific visual identity; existing actions preserved --}}
    <header class="jarvis-module-hero jarvis-accent-violet" data-jarvis-module="leads" aria-label="Tasks and follow-ups">
      <div class="jarvis-module-hero__identity"><span class="jarvis-module-hero__glyph" aria-hidden="true">✦</span><div>
        <div class="jarvis-module-hero__eyebrow">JARVIS ONE <span aria-hidden="true">/</span> LEADS</div>
        <h1>Tasks and follow-ups</h1><p>Organize prospects, manage pipelines and track follow-ups.</p>
      </div></div>
      <span class="jarvis-module-hero__section">Leads Workspace</span>
    </header>

    <div class="flex flex-wrap items-center justify-between gap-2"><h2 class="text-xl font-semibold">Tasks and follow-ups</h2>
        <div class="flex gap-3 text-sm"><a class="text-indigo-600" href="{{ route('leads.tasks', $mine ? [] : ['mine' => 1]) }}">{{ $mine ? 'Everyone’s tasks' : 'Only my tasks' }}</a><a class="text-indigo-600" href="{{ route('leads.board') }}">Board</a></div></div>
    @if (session('status'))<p class="rounded bg-green-50 p-3 text-sm text-green-700">{{ session('status') }}</p>@endif
    <section class="rounded-lg bg-white p-5 shadow">
        <h3 class="mb-2 font-medium">Open tasks</h3>
        @forelse ($tasks as $t)
            <div class="flex flex-wrap items-center justify-between gap-2 border-t py-2 text-sm">
                <span>{{ $t->title }} <a class="text-indigo-600" href="{{ route('leads.show', trim($t->lead_id)) }}">· {{ $t->name }}</a>
                    @if ($t->due_at)<span class="ml-1 text-xs {{ \Illuminate\Support\Carbon::parse($t->due_at)->isPast() ? 'text-red-700' : 'text-gray-500' }}">{{ $fmt($t->due_at) }}</span>@endif</span>
                @if ($canManage)<form method="POST" action="{{ route('leads.tasks.done', trim($t->id)) }}">@csrf<x-button variant="ghost">End</x-button></form>@endif
            </div>
        @empty<p class="text-sm text-gray-500">No open tasks.</p>@endforelse
    </section>
    <section class="rounded-lg bg-white p-5 shadow">
        <h3 class="mb-2 font-medium">Follow-ups for the next 14 days (and overdue)</h3>
        @forelse ($followUps as $f)
            <a href="{{ route('leads.show', trim($f->id)) }}" class="flex justify-between border-t py-2 text-sm hover:bg-gray-50"><span>{{ $f->name }}</span><span class="{{ \Illuminate\Support\Carbon::parse($f->next_follow_up_at)->isPast() ? 'text-red-700' : 'text-gray-600' }}">{{ $fmt($f->next_follow_up_at, 'd M Y') }}</span></a>
        @empty<p class="text-sm text-gray-500">No follow-ups.</p>@endforelse
    </section>
</x-layouts.app>
