<x-layouts.app title="Journey">
    {{-- JARVIS ONE: page-specific visual identity; existing actions preserved --}}
    <header class="jarvis-module-hero jarvis-accent-violet" data-jarvis-module="journey" aria-label="Journey">
      <div class="jarvis-module-hero__identity"><span class="jarvis-module-hero__glyph" aria-hidden="true">✦</span><div>
        <div class="jarvis-module-hero__eyebrow">JARVIS ONE <span aria-hidden="true">/</span> JOURNEY</div>
        <h1>Journey</h1>
      </div></div>
      <span class="jarvis-module-hero__section">Journey Workspace</span>
    </header>

    <h2 class="text-xl font-semibold">Journey — what your assistant knows</h2>
    <p class="mt-1 max-w-3xl text-sm text-gray-600">Everything the assistant has been told or taught, newest first: memories, skills and scheduled jobs. Nothing here is hidden from you, and nothing is learned without your approval. Change things on the <a class="text-indigo-600 underline" href="{{ route('memory.index') }}">Memory</a>, <a class="text-indigo-600 underline" href="{{ route('skills.index') }}">Skills</a> and <a class="text-indigo-600 underline" href="{{ route('jobs.index') }}">Scheduled jobs</a> pages.</p>

    <section class="mt-3 max-w-3xl rounded-lg bg-white p-4 text-sm shadow">
        <h3 class="font-semibold">What the AI is told in every chat</h3>
        @if (! $useInChat)
            <p class="mt-1 text-gray-500">Memory is switched off for your chats, so nothing below is sent.</p>
        @endif
        @forelse ($inChat as $m)
            <p class="mt-1 text-xs {{ $useInChat ? '' : 'text-gray-400 line-through' }}">• {{ $m['content'] }} @if ($m['scope'] === 'business') <span class="text-gray-400">(business)</span> @endif</p>
        @empty
            <p class="mt-1 text-xs text-gray-500">Nothing yet.</p>
        @endforelse
        <p class="mt-3 flex gap-2 text-xs"><a class="rounded border px-2 py-1" href="{{ route('journey.export', 'md') }}">Download as Markdown</a><a class="rounded border px-2 py-1" href="{{ route('journey.export', 'json') }}">Download as JSON</a></p>
    </section>

    <form method="GET" class="mt-4 flex max-w-3xl flex-wrap items-end gap-2 text-sm">
        <label class="grid gap-1">Show<select name="kind" class="rounded border px-2 py-1"><option value="">Everything</option>@foreach ($kinds as $k => $l)<option value="{{ $k }}" @selected($kind === $k)>{{ $l }}</option>@endforeach</select></label>
        <label class="grid flex-1 gap-1">Search<input name="q" value="{{ $q }}" maxlength="100" class="rounded border px-2 py-1"></label>
        <button class="rounded bg-indigo-600 px-3 py-1 text-white">Filter</button>
    </form>

    <ol class="mt-4 max-w-3xl border-l-2 border-indigo-200 pl-4">
        @forelse ($items as $i)
            <li class="mb-3">
                <p class="text-xs text-gray-500">{{ \Illuminate\Support\Carbon::parse($i['at'])->timezone(config('app.timezone'))->format('d M Y, H:i') }} · {{ $kinds[$i['kind']] }}</p>
                <a href="{{ $i['url'] }}" class="text-sm font-medium text-indigo-700 hover:underline">{{ \Illuminate\Support\Str::limit($i['title'], 160) }}</a>
                @if ($i['detail'] !== '')<p class="text-xs text-gray-600">{{ $i['detail'] }}</p>@endif
            </li>
        @empty
            <li class="text-sm text-gray-500">Nothing matches.</li>
        @endforelse
    </ol>
</x-layouts.app>
