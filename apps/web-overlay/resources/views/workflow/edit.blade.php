<x-layouts.app title="Edit workflow">
    {{-- JARVIS ONE: page-specific visual identity; existing actions preserved --}}
    <header class="jarvis-module-hero jarvis-accent-violet" data-jarvis-module="workflow" aria-label="Edit workflow">
      <div class="jarvis-module-hero__identity"><span class="jarvis-module-hero__glyph" aria-hidden="true">✦</span><div>
        <div class="jarvis-module-hero__eyebrow">JARVIS ONE <span aria-hidden="true">/</span> WORKFLOW</div>
        <h1>Edit workflow</h1>
      </div></div>
      <span class="jarvis-module-hero__section">Workflow Workspace</span>
    </header>

    <a href="{{ route('agents.index') }}" class="text-sm text-indigo-700">← Go back</a>
    <h2 class="mt-1 text-xl font-semibold">Edit workflow</h2>
    <p class="mt-1 rounded bg-amber-50 p-3 text-sm text-amber-800">In this version steps must be edited as JSON (a visual editor comes later). Rules are validated on save: max 30 steps, no loops, no money tools, an approval step is needed before any tool that sends outside. Step types: <b>ai</b> (prompt, save_as, max_tokens, next), <b>tool</b> (tool, args, save_as, next), <b>condition</b> (var, op, value, then, else), <b>approval</b> (message, next), <b>output</b> (text). Write variables <code>@{{Name}}</code> in the form (English names); external text given to the AI <code>"""…"""</code>-keep inside.</p>
    @if ($errors->any())<p class="mt-2 rounded bg-red-50 p-3 text-sm text-red-700">{{ $errors->first() }}</p>@endif
    @if (session('status'))<p class="mt-2 rounded bg-green-50 p-3 text-sm text-green-700">{{ session('status') }}</p>@endif
    <form method="POST" action="{{ route('agents.save', $w['id']) }}" class="mt-3 grid gap-2 rounded-lg bg-white p-4 text-sm shadow">@csrf
        <label>Name<input name="name" maxlength="120" required value="{{ old('name', $w['name']) }}" class="mt-1 w-full rounded border px-2 py-1"></label>
        <label>Description<input name="description" maxlength="500" value="{{ old('description', $w['description']) }}" class="mt-1 w-full rounded border px-2 py-1"></label>
        <div class="flex flex-wrap gap-3">
            <label>Type<select name="category" class="mt-1 rounded border px-2 py-1">@foreach ($categories as $k => $l)<option value="{{ $k }}" @selected(old('category', $w['category']) === $k)>{{ $l }}</option>@endforeach</select></label>
            <label>Daily run time (HH:MM, empty = manual only)<input name="schedule" maxlength="5" placeholder="08:00" value="{{ old('schedule', $w['schedule_time']) }}" class="mt-1 block w-28 rounded border px-2 py-1"></label>
            <label>Credit limit per run<input name="max_credits" maxlength="10" value="{{ old('max_credits', $w['max_credits']) }}" class="mt-1 block w-28 rounded border px-2 py-1"></label>
            <label class="flex items-end gap-1"><input type="checkbox" name="enabled" value="1" @checked(old('name') === null ? $w['enabled'] : old('enabled') === '1')> Active</label></div>
        <label>Definition (JSON)<textarea name="definition" rows="22" class="mt-1 w-full rounded border px-2 py-1 font-mono text-xs">{{ old('definition', json_encode($w['definition'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) }}</textarea></label>
        <div><button class="rounded bg-indigo-600 px-3 py-1 text-white">Save</button></div></form>
    <section class="mt-3 rounded-lg bg-white p-4 text-sm shadow"><h3 class="font-semibold">Usable tools</h3>
        <ul class="mt-1 list-disc pl-5">@foreach ($tools as $k => $t)<li><code>{{ $k }}</code> — {{ $t->label() }} · {{ $risks[$t->risk()] ?? '' }} · Arguments: {{ implode(', ', array_map(fn ($a) => rtrim($a, '?').(str_ends_with($a, '?') ? '(optional)' : ''), array_keys($t->args()))) ?: 'None' }}</li>@endforeach</ul></section>
</x-layouts.app>
