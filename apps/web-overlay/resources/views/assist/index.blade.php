<x-layouts.app title="Writing assistant">
    {{-- JARVIS ONE: page-specific visual identity; existing actions preserved --}}
    <header class="jarvis-module-hero jarvis-accent-violet" data-jarvis-module="assist" aria-label="Writing assistant">
      <div class="jarvis-module-hero__identity"><span class="jarvis-module-hero__glyph" aria-hidden="true">✦</span><div>
        <div class="jarvis-module-hero__eyebrow">JARVIS ONE <span aria-hidden="true">/</span> ASSIST</div>
        <h1>Writing assistant</h1>
      </div></div>
      <span class="jarvis-module-hero__section">Assist Workspace</span>
    </header>

    @php($res = session('assist'))
    <h2 class="text-xl font-semibold">Writing assistant — meeting summary and email</h2>
    <p class="mt-1 rounded bg-amber-50 p-3 text-sm text-amber-800">Paste text — AI will give a summary or draft. <b>Nothing is sent or saved</b>: You copy and send the email draft yourself; leaving the page loses the result. AI can make mistakes — check dates, names and figures against the original. Each use costs credits. Do not paste secrets such as passwords/card numbers (the text goes to the AI provider). A meeting recording file (≤5 MB, about 5 minutes) is first turned into text and then summarized — the recording is not stored.</p>
    @if ($errors->any())<p class="mt-2 rounded bg-red-50 p-3 text-sm text-red-700">{{ $errors->first() }}</p>@endif
    @if (session('status'))<p class="mt-2 rounded bg-green-50 p-3 text-sm text-green-700">{{ session('status') }}</p>@endif

    <form method="POST" action="{{ route('assist.run') }}" enctype="multipart/form-data" class="mt-3 grid gap-2 rounded-lg bg-white p-4 text-sm shadow" onsubmit="this.querySelector('button').disabled = true; this.querySelector('button').textContent = 'Working…'">@csrf
        <label>What do you want to do<select name="mode" class="mt-1 w-full rounded border px-2 py-1 sm:w-80">@foreach ($modes as $k => $label)<option value="{{ $k }}" @selected(old('mode', 'meeting') === $k)>{{ $label }}</option>@endforeach</select></label>
        <label>Pasted text (meeting transcript / email) — meeting ≤{{ number_format($max['meeting']) }}, Email ≤{{ number_format($max['email']) }} characters<textarea name="text" rows="9" class="mt-1 w-full rounded border px-2 py-1">{{ old('text') }}</textarea></label>
        <label>Meeting audio (optional, only "Meeting summary"-in; if given, the text above is ignored)<input type="file" name="audio" accept="audio/*,video/webm,video/mp4,.m4a,.mp3,.wav,.ogg,.webm" class="mt-1 block w-full text-sm"></label>
        <label>"Write email" If replying: what you want to say (optional — which points; required for a new email)<textarea name="points" rows="3" maxlength="2000" class="mt-1 w-full rounded border px-2 py-1" placeholder="For example: price cannot be cut by 15%, but delivery is free on orders over 10,000">{{ old('points') }}</textarea></label>
        <label>Tone<select name="tone" class="mt-1 w-full rounded border px-2 py-1 sm:w-60">@foreach ($tones as $k => $label)<option value="{{ $k }}" @selected(old('tone', 'formal') === $k)>{{ $label }}</option>@endforeach</select></label>
        <div><button class="rounded bg-indigo-600 px-3 py-1 text-white disabled:opacity-60">Create</button></div></form>

    @if ($res)
    @php($r = $res['result'])
    <section class="mt-3 rounded-lg border border-indigo-200 bg-indigo-50 p-4 text-sm"><p class="font-semibold text-indigo-900">{{ $modes[$res['mode']] }} — AI-draft, please verify {{ $res['note'] ?? '' }}</p>
        @if (! empty($res['heard']))<details class="mt-1 text-xs"><summary class="cursor-pointer">Text obtained from the recording (if it has errors the summary will too)</summary><p class="mt-1 whitespace-pre-line rounded bg-white p-2">{{ $res['heard'] }}</p></details>@endif
        @if ($res['mode'] === 'meeting')
            @if ($r['summary'] !== '')<p class="mt-2 whitespace-pre-line">{{ $r['summary'] }}</p>@endif
            @if ($r['decisions'] !== [])<p class="mt-2 font-semibold">Decisions</p><ul class="list-disc pl-5">@foreach ($r['decisions'] as $x)<li>{{ $x }}</li>@endforeach</ul>@endif
            @if ($r['actions'] !== [])
            <form method="POST" action="{{ route('assist.tasks') }}" class="mt-2">@csrf<p class="font-semibold">Action items — tick and add to tasks</p>
                @foreach ($r['actions'] as $i => $a)
                <div class="mt-1 flex flex-wrap items-center gap-2 rounded bg-white p-2"><input type="checkbox" name="items[{{ $i }}][add]" value="1" checked>
                    <input name="items[{{ $i }}][task]" maxlength="200" value="{{ $a['task'] }}{{ $a['owner'] !== '' ? ' ('.$a['owner'].')' : '' }}" class="min-w-[14rem] flex-1 rounded border px-2 py-0.5">
                    <input type="date" name="items[{{ $i }}][due]" value="{{ $a['due'] }}" class="rounded border px-2 py-0.5"></div>@endforeach
                <button class="mt-2 rounded bg-indigo-600 px-3 py-1 text-white">Add ticked items to tasks</button></form>@endif
        @elseif ($res['mode'] === 'summary')
            <p class="mt-2 whitespace-pre-line">{{ $r['summary'] }}</p>
            @if ($r['points'] !== [])<ul class="mt-2 list-disc pl-5">@foreach ($r['points'] as $x)<li>{{ $x }}</li>@endforeach</ul>@endif
            @if ($r['deadlines'] !== [])<p class="mt-2"><b>Deadline:</b> {{ implode('; ', $r['deadlines']) }}</p>@endif
            <p class="mt-2">{{ $r['needs_reply'] ? '↩ A reply seems to be needed.' : 'No reply seems to be needed.' }}</p>
        @else
            @if ($r['subject'] !== '')<p class="mt-2"><b>Subject:</b> {{ $r['subject'] }}</p>@endif
            <textarea readonly rows="10" class="mt-2 w-full rounded border bg-white px-2 py-1" onclick="this.select()">{{ $r['body'] }}</textarea>
            <p class="mt-1 text-xs text-gray-600">Copy and paste into your email. Fill in the [bracketed] places yourself.</p>
        @endif</section>
    @endif
</x-layouts.app>
