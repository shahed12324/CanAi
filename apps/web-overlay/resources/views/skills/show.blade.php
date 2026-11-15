<x-layouts.app :title="$s->name">
    {{-- JARVIS ONE: page-specific visual identity; existing actions preserved --}}
    <header class="jarvis-module-hero jarvis-accent-violet" data-jarvis-module="skills" aria-label="Skills details">
      <div class="jarvis-module-hero__identity"><span class="jarvis-module-hero__glyph" aria-hidden="true">✦</span><div>
        <div class="jarvis-module-hero__eyebrow">JARVIS ONE <span aria-hidden="true">/</span> SKILLS</div>
        <h1>Skills details</h1>
      </div></div>
      <span class="jarvis-module-hero__section">Skills Workspace</span>
    </header>
    <p class="text-sm"><a class="text-indigo-600 underline" href="{{ route('skills.index') }}">← Skills</a></p>
    <h2 class="mt-1 text-xl font-semibold">/{{ $s->slug }} — {{ $s->name }} <span class="text-sm font-normal text-gray-500">v{{ $s->version }}</span></h2>
    @if (session('status'))<p class="mt-2 rounded bg-green-50 p-3 text-sm text-green-700">{{ session('status') }}</p>@endif
    @if ($errors->any())<p class="mt-2 rounded bg-red-50 p-3 text-sm text-red-700">{{ $errors->first() }}</p>@endif

    @if ($s->status === 'active')
        <section class="mt-3 max-w-2xl rounded-lg bg-white p-4 text-sm shadow">
            <h3 class="font-semibold">Try it (uses credits like a chat message)</h3>
            <form method="POST" action="{{ route('skills.run', $s->id) }}" class="mt-2 flex gap-2" onsubmit="var b=this.querySelector('button');b.disabled=true;b.textContent='Running…'">@csrf
                <input name="input" maxlength="1500" placeholder="Your request, e.g. last week" class="flex-1 rounded border px-2 py-1"><button class="rounded bg-indigo-600 px-3 py-1 text-white">Run</button></form>
            @if ($answer)
                <div class="mt-3 whitespace-pre-line rounded border p-3 {{ $answer['ok'] ? '' : 'border-red-300 bg-red-50 text-red-700' }}">{{ $answer['text'] }}</div>
                @if ($answer['ok'])
                    <form method="POST" action="{{ route('skills.feedback', $s->id) }}" class="mt-2 flex flex-wrap items-center gap-2">@csrf
                        <input name="note" maxlength="500" placeholder="What was good or wrong? (optional)" class="min-w-[14rem] flex-1 rounded border px-2 py-1">
                        <button name="rating" value="1" class="rounded border px-2 py-1">👍</button><button name="rating" value="-1" class="rounded border px-2 py-1">👎</button></form>
                @endif
            @endif
            <form method="POST" action="{{ route('skills.improve', $s->id) }}" class="mt-3">@csrf <button class="rounded border border-indigo-600 px-3 py-1 text-indigo-700" onclick="this.textContent='Thinking…'">Suggest an improvement from my feedback (uses credits)</button></form>
        </section>
    @endif

    @foreach ($versions->where('state', 'proposed') as $p)
        <section class="mt-4 max-w-3xl rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm">
            <h3 class="font-semibold">Suggested version {{ $p->version }} — waiting for you</h3>
            <p class="mt-1 text-xs text-gray-600">{{ $p->note }}. It is not used until you approve it.</p>
            <p class="mt-2 font-medium">{{ $p->name }}</p><p class="text-xs text-gray-600">{{ $p->description }}</p>
            <pre class="mt-2 whitespace-pre-wrap rounded bg-white p-2 text-xs">{{ $p->instructions }}</pre>
            <div class="mt-2 flex gap-2">
                <form method="POST" action="{{ route('skills.decide', [$s->id, $p->id, 'approve']) }}">@csrf <button class="rounded bg-indigo-600 px-3 py-1 text-white">Approve</button></form>
                <form method="POST" action="{{ route('skills.decide', [$s->id, $p->id, 'reject']) }}">@csrf <button class="rounded border px-3 py-1">Dismiss</button></form>
            </div>
        </section>
    @endforeach

    <h3 class="mt-5 font-semibold">Edit (saved as a new version)</h3>
    <div class="mt-2"><x-skills._form :action="route('skills.update', $s->id)" method="PUT" :v="['name' => $s->name, 'description' => $s->description, 'instructions' => $s->instructions]" button="Save new version" /></div>

    <section class="mt-5 max-w-3xl">
        <h3 class="font-semibold">Versions</h3>
        @foreach ($versions->whereIn('state', ['current', 'old', 'rejected']) as $v)
            <div class="mt-1 flex items-center justify-between rounded bg-white p-2 text-xs shadow">
                <span>v{{ $v->version }} · {{ $v->state }} · {{ $v->note }} · {{ \Illuminate\Support\Carbon::parse($v->created_at)->format('d M Y H:i') }}</span>
                @if ($v->state !== 'current')<form method="POST" action="{{ route('skills.decide', [$s->id, $v->id, 'restore']) }}">@csrf <button class="rounded border px-2 py-0.5">Restore</button></form>@endif
            </div>
        @endforeach
        @if ($feedback->isNotEmpty())
            <h3 class="mt-4 font-semibold">Recent feedback</h3>
            @foreach ($feedback as $f)<p class="mt-1 text-xs text-gray-600">{{ $f->rating > 0 ? '👍' : '👎' }} {{ $f->note }}</p>@endforeach
        @endif
        @if ($s->status === 'active')
            <form method="POST" action="{{ route('skills.archive', $s->id) }}" class="mt-4" onsubmit="return confirm('Archive this skill?')">@csrf <button class="rounded border border-red-300 px-3 py-1 text-xs text-red-700">Archive</button></form>
        @endif
    </section>
</x-layouts.app>
