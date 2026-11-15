<x-layouts.app title="Pipeline board">
    {{-- JARVIS ONE: page-specific visual identity; existing actions preserved --}}
    <header class="jarvis-module-hero jarvis-accent-violet" data-jarvis-module="leads" aria-label="Pipeline board">
      <div class="jarvis-module-hero__identity"><span class="jarvis-module-hero__glyph" aria-hidden="true">✦</span><div>
        <div class="jarvis-module-hero__eyebrow">JARVIS ONE <span aria-hidden="true">/</span> LEADS</div>
        <h1>Pipeline board</h1><p>Organize prospects, manage pipelines and track follow-ups.</p>
      </div></div>
      <span class="jarvis-module-hero__section">Leads Workspace</span>
    </header>

    <div class="flex flex-wrap items-center justify-between gap-2"><h2 class="text-xl font-semibold">Pipeline board</h2>
        <div class="flex gap-3 text-sm"><a class="text-indigo-600" href="{{ route('leads.index') }}">List</a><a class="text-indigo-600" href="{{ route('leads.tasks') }}">Task</a></div></div>
    @if (session('status'))<p class="rounded bg-green-50 p-3 text-sm text-green-700">{{ session('status') }}</p>@endif
    @if ($errors->any())<div class="rounded bg-red-50 p-3 text-sm text-red-700">@foreach ($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div>@endif
    <div class="flex flex-wrap items-center gap-2 text-sm">
        <span class="text-gray-600">Tag:</span>
        <a href="{{ route('leads.board') }}" class="rounded px-2 py-0.5 {{ $tag === '' ? 'bg-indigo-600 text-white' : 'bg-gray-100' }}">All</a>
        @foreach ($tags as $t)<a href="{{ route('leads.board', ['tag' => $t->tag]) }}" class="rounded px-2 py-0.5 {{ $tag === $t->tag ? 'bg-indigo-600 text-white' : 'bg-gray-100' }}">{{ $t->tag }} <span class="opacity-60">{{ $t->n }}</span></a>@endforeach
    </div>
    <div class="grid gap-3 md:grid-cols-3 xl:grid-cols-6">
        @foreach ($cols as $stage => $col)
            <section class="rounded-lg bg-gray-100 p-2">
                <h3 class="mb-2 flex justify-between px-1 text-sm font-medium"><span>{{ $stages[$stage] }}</span><span class="text-gray-500">{{ $col['total'] }}</span></h3>
                <div class="space-y-2">
                    @forelse ($col['cards'] as $c)
                        <article class="rounded-md bg-white p-2 text-sm shadow-sm">
                            <a class="block truncate font-medium text-indigo-700" href="{{ route('leads.show', trim($c->id)) }}">{{ $c->name }}</a>
                            <p class="truncate text-xs text-gray-500">{{ $c->domain }}</p>
                            <p class="mt-1 flex flex-wrap items-center gap-1 text-xs">
                                <span class="rounded bg-gray-100 px-1 tabular-nums">Score {{ $c->score }}</span>
                                @if ($c->open_tasks > 0)<span class="rounded bg-amber-50 px-1 text-amber-800">Task {{ $c->open_tasks }}</span>@endif
                                @if ($c->next_follow_up_at)<span class="rounded px-1 {{ \Illuminate\Support\Carbon::parse($c->next_follow_up_at)->isPast() ? 'bg-red-50 text-red-700' : 'bg-blue-50 text-blue-700' }}">Follow-up {{ \Illuminate\Support\Carbon::parse($c->next_follow_up_at)->setTimezone($tz)->format('d M') }}</span>@endif
                                @foreach (array_slice(array_filter(explode(',', (string) $c->tags)), 0, 3) as $t)<span class="rounded bg-indigo-50 px-1 text-indigo-700">{{ $t }}</span>@endforeach
                            </p>
                            @if ($canManage)
                                <form method="POST" action="{{ route('leads.stage', trim($c->id)) }}" class="mt-2 flex gap-1">@csrf<input type="hidden" name="back_to_board" value="1">
                                    <select name="stage" class="min-w-0 flex-1 rounded border-gray-300 py-0.5 text-xs" aria-label="Stage">@foreach ($stages as $k => $l)<option value="{{ $k }}" @selected($c->stage === $k)>{{ $l }}</option>@endforeach</select>
                                    <button class="rounded bg-gray-200 px-2 text-xs">Remove</button>
                                </form>
                            @endif
                        </article>
                    @empty<p class="px-1 py-2 text-xs text-gray-400">Empty</p>@endforelse
                    @if ($col['total'] > $perColumn)<a class="block px-1 text-xs text-indigo-600" href="{{ route('leads.index', ['stage' => $stage]) }}">See {{ $col['total'] - $perColumn }} more →</a>@endif
                </div>
            </section>
        @endforeach
    </div>
</x-layouts.app>
