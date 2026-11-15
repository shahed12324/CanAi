<x-layouts.app title="Skills">
    {{-- JARVIS ONE: page-specific visual identity; existing actions preserved --}}
    <header class="jarvis-module-hero jarvis-accent-violet" data-jarvis-module="skills" aria-label="Skills">
      <div class="jarvis-module-hero__identity"><span class="jarvis-module-hero__glyph" aria-hidden="true">✦</span><div>
        <div class="jarvis-module-hero__eyebrow">JARVIS ONE <span aria-hidden="true">/</span> SKILLS</div>
        <h1>Skills</h1>
      </div></div>
      <span class="jarvis-module-hero__section">Skills Workspace</span>
    </header>

    <h2 class="text-xl font-semibold">Skills</h2>
    <p class="mt-1 max-w-3xl text-sm text-gray-600">A skill is a saved procedure the AI follows. Type <code>/skill-name your request</code> in the chat, Telegram, WhatsApp or a scheduled job. The AI can draft a skill from a task that worked — you always read and approve it first, and a skill never runs anything outside the tools your chat already has.</p>
    @if (session('status'))<p class="mt-2 rounded bg-green-50 p-3 text-sm text-green-700">{{ session('status') }}</p>@endif
    @if ($errors->any())<p class="mt-2 rounded bg-red-50 p-3 text-sm text-red-700">{{ $errors->first() }}</p>@endif

    @if ($draft)
        <h3 class="mt-4 font-semibold">Draft from the AI — check it before saving</h3>
        <div class="mt-2"><x-skills._form :action="route('skills.store')" :v="$draft" button="Save skill" /></div>
    @endif

    <section class="mt-4 grid max-w-4xl gap-4 lg:grid-cols-2">
        <form method="POST" action="{{ route('skills.learn') }}" class="grid gap-2 rounded-lg bg-white p-4 text-sm shadow">
            @csrf
            <h3 class="font-semibold">Let the AI learn a skill (uses a few credits)</h3>
            <label class="grid gap-1">From one of your chats
                <select name="conversation_id" class="rounded border px-2 py-1"><option value="">— none —</option>@foreach ($convs as $c)<option value="{{ $c->id }}">{{ \Illuminate\Support\Str::limit($c->title, 60) }}</option>@endforeach</select></label>
            <label class="grid gap-1">…or describe how the task is done
                <textarea name="source" rows="4" maxlength="8000" placeholder="Each Monday I check last week's orders, list unpaid ones and write a polite reminder for each customer" class="rounded border px-2 py-1">{{ old('source') }}</textarea></label>
            <div><button type="submit" class="rounded border border-indigo-600 px-3 py-1 text-indigo-700" onclick="this.textContent='Learning…'">Draft a skill</button></div>
        </form>
        <div>
            <h3 class="text-sm font-semibold">Or write one by hand</h3>
            <div class="mt-2"><x-skills._form :action="route('skills.store')" :v="[]" button="Save skill" /></div>
        </div>
    </section>

    <section class="mt-5 max-w-4xl">
        <h3 class="font-semibold">Your skills ({{ $skills->where('status', 'active')->count() }}/{{ $max }})</h3>
        @forelse ($skills as $s)
            <a href="{{ route('skills.show', $s->id) }}" class="mt-2 block rounded-lg bg-white p-3 text-sm shadow hover:ring-2 hover:ring-indigo-300 {{ $s->status === 'archived' ? 'opacity-60' : '' }}">
                <span class="font-medium">/{{ $s->slug }}</span> — {{ $s->name }}
                <span class="ml-2 text-xs text-gray-500">v{{ $s->version }} · {{ $s->source }} · used {{ $s->use_count }}× @if ($s->status === 'archived') · archived @endif</span>
                <span class="mt-1 block text-xs text-gray-600">{{ $s->description }}</span>
            </a>
        @empty
            <p class="mt-2 text-sm text-gray-500">No skills yet.</p>
        @endforelse
    </section>
</x-layouts.app>
