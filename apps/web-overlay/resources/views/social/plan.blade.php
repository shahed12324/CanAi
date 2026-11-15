<x-layouts.app title="AI Content plan">
    {{-- JARVIS ONE: page-specific visual identity; existing actions preserved --}}
    <header class="jarvis-module-hero jarvis-accent-violet" data-jarvis-module="social" aria-label="AI Content plan">
      <div class="jarvis-module-hero__identity"><span class="jarvis-module-hero__glyph" aria-hidden="true">✦</span><div>
        <div class="jarvis-module-hero__eyebrow">JARVIS ONE <span aria-hidden="true">/</span> SOCIAL</div>
        <h1>AI Content plan</h1><p>Publish content, monitor channels and manage social activity.</p>
      </div></div>
      <span class="jarvis-module-hero__section">Social Workspace</span>
    </header>

    <p class="text-sm"><a class="text-indigo-600 hover:underline" href="{{ route('social.index') }}">← Social planner</a></p>
    <h2 class="mt-1 text-xl font-semibold">AI Content plan agent</h2>
    <p class="mt-1 rounded bg-amber-50 p-3 text-sm text-amber-800">The agent only gives <b>Proposals</b> — only if you pick them do they go into the planner as normal <b>Drafts</b> and then you must pick an image/video and send for approval. Nothing is posted by itself. It thinks over your recent best posts (if insights exist) and posts already planned for that period. Uses a little text credit; up to 10 times a day.</p>
    @if ($errors->any())<p class="mt-2 rounded bg-red-50 p-3 text-sm text-red-700">{{ $errors->first() }}</p>@endif
    @if (session('status'))<p class="mt-2 rounded bg-green-50 p-3 text-sm text-green-700">{{ session('status') }}</p>@endif

    <form method="POST" action="{{ route('social.plan.generate') }}" class="mt-3 grid gap-3 rounded-lg bg-white p-4 text-sm shadow" onsubmit="var b=this.querySelector('button[type=submit]'); b.disabled = true; b.textContent = 'Agent is thinking…'">@csrf
        <input type="hidden" name="rid" value="{{ $rid }}">
        <label class="grid gap-1">Business / offer / goal (give true facts only — the agent will not invent prices or claims)
            <textarea name="brief" rows="4" maxlength="1500" required placeholder="For example: We sell dry fruits in Dhaka. Free delivery on cashews this week. Goal: increase orders in the inbox." class="rounded border px-2 py-1">{{ old('brief') }}</textarea></label>
        <div class="flex flex-wrap gap-4">
            <label>For how many days? <input type="number" name="days" min="1" max="14" value="{{ old('days', 7) }}" class="w-20 rounded border px-2 py-1"></label>
            <label>How many posts? <input type="number" name="count" min="1" max="14" value="{{ old('count', 5) }}" class="w-20 rounded border px-2 py-1"></label>
            <label>Tone <select name="tone" class="rounded border px-2 py-1">@foreach ($tones as $k => $l)<option value="{{ $k }}" @selected(old('tone', 'friendly') === $k)>{{ $l }}</option>@endforeach</select></label></div>
        <fieldset class="flex flex-wrap gap-3"><legend class="mb-1">Platform</legend>@foreach ($platforms as $k => $l)<label class="flex items-center gap-1"><input type="checkbox" name="platforms[]" value="{{ $k }}" @checked(in_array($k, old('platforms', ['facebook']), true))> {{ $l }}</label>@endforeach</fieldset>
        <div><button type="submit" class="rounded bg-indigo-600 px-3 py-1 text-white">Make plan</button></div>
    </form>

    @if ($proposals)
    <form method="POST" action="{{ route('social.plan.accept') }}" class="mt-4 grid gap-3">@csrf
        <h3 class="font-semibold">Suggested posts ({{ count($proposals) }}) — Period {{ $tz }}</h3>
        @foreach ($proposals as $i => $p)
        <fieldset class="grid gap-2 rounded-lg bg-white p-4 text-sm shadow">
            <label class="flex items-center gap-2 font-medium"><input type="checkbox" name="items[{{ $i }}][pick]" value="1" checked> {{ $platforms[$p['platform']] ?? $p['platform'] }} — Keep</label>
            <input type="hidden" name="items[{{ $i }}][platform]" value="{{ $p['platform'] }}">
            <label class="grid gap-1">Name<input name="items[{{ $i }}][title]" maxlength="120" value="{{ $p['title'] }}" class="rounded border px-2 py-1"></label>
            <label class="grid gap-1">Caption<textarea name="items[{{ $i }}][caption]" rows="5" maxlength="3000" class="rounded border px-2 py-1">{{ $p['caption'] }}</textarea></label>
            <label class="grid gap-1">Period<input type="datetime-local" name="items[{{ $i }}][scheduled]" value="{{ $p['scheduled'] }}" class="w-60 rounded border px-2 py-1"></label>
            <label class="grid gap-1">Media ideas ({{ $p['media'] === 'video' ? 'Video' : ($p['media'] === 'design' ? 'Image/design' : 'works without too') }})<input name="items[{{ $i }}][idea]" maxlength="500" value="{{ $p['idea'] }}" class="rounded border px-2 py-1"></label>
        </fieldset>
        @endforeach
        <div><button class="rounded bg-green-600 px-3 py-1 text-white">Add selected to drafts</button></div>
    </form>
    @endif
</x-layouts.app>
