<x-layouts.app :title="$w['name']">
    {{-- JARVIS ONE: page-specific visual identity; existing actions preserved --}}
    <header class="jarvis-module-hero jarvis-accent-violet" data-jarvis-module="workflow" aria-label="Workflow">
      <div class="jarvis-module-hero__identity"><span class="jarvis-module-hero__glyph" aria-hidden="true">✦</span><div>
        <div class="jarvis-module-hero__eyebrow">JARVIS ONE <span aria-hidden="true">/</span> WORKFLOW</div>
        <h1>{{ $w['name'] }}</h1>
      </div></div>
      <span class="jarvis-module-hero__section">{{ $groups[$group] ?? 'Workflow' }}</span>
    </header>

    @php($st = ['queued' => 'In queue', 'running' => 'Running', 'waiting_approval' => 'Awaiting approval', 'done' => 'End', 'failed' => 'Failed', 'cancelled' => 'Closed'])
    @php($ty = ['ai' => 'AI', 'tool' => 'Tool', 'condition' => 'Condition', 'approval' => 'Approval', 'delay' => 'Wait', 'parallel' => 'Parallel', 'output' => 'Results'])
    @php($badge = ['active' => 'bg-green-100 text-green-800', 'scheduled' => 'bg-blue-100 text-blue-800', 'running' => 'bg-violet-100 text-violet-800', 'paused' => 'bg-amber-100 text-amber-800', 'disabled' => 'bg-gray-200 text-gray-700', 'attention' => 'bg-red-100 text-red-800'])
    @php($labels = ['active' => 'Active', 'scheduled' => 'Scheduled', 'running' => 'Running', 'paused' => 'Paused', 'disabled' => 'Off', 'attention' => 'Needs attention'])
    <a href="{{ route('agents.index') }}" class="text-sm text-indigo-700">← All workflows</a>
    @if ($errors->any())<p class="mt-2 rounded bg-red-50 p-3 text-sm text-red-700" role="alert">{{ $errors->first() }}</p>@endif
    @if (session('status'))<p class="mt-2 rounded bg-green-50 p-3 text-sm text-green-700" role="status">{{ session('status') }}</p>@endif

    {{-- header: rename + status --}}
    <section class="mt-3 rounded-lg bg-white p-4 text-sm shadow" aria-label="Details">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <p class="text-xs text-gray-500">Version {{ $w['version'] }} · {{ $groups[$group] ?? '' }}@if ($templateName) · from template “{{ $templateName }}”@endif{{ $w['schedule_time'] ? ' · Daily '.$w['schedule_time'] : ' · Manual only' }}</p>
            <span class="rounded-full px-2 py-0.5 text-xs {{ $badge[$status] ?? '' }}" data-status="{{ $status }}">{{ $labels[$status] ?? $status }}</span></div>
        <form method="POST" action="{{ route('agents.rename', $w['id']) }}" class="mt-2 grid gap-2 sm:grid-cols-3" aria-label="Rename">@csrf
            <label class="text-xs">Name<input name="name" required maxlength="120" value="{{ old('name', $w['name']) }}" class="mt-1 w-full rounded border px-2 py-1 text-sm"></label>
            <label class="text-xs sm:col-span-2">Description<input name="description" maxlength="500" value="{{ old('description', $w['description']) }}" class="mt-1 w-full rounded border px-2 py-1 text-sm"></label>
            <div class="sm:col-span-3"><button class="rounded bg-indigo-600 px-3 py-1 text-white">Save name</button> <span class="text-xs text-gray-500">Renaming does not change the steps, the schedule, the history or the template it came from.</span></div>
        </form>
        <div class="mt-3 flex flex-wrap gap-3">
            @if ($w['enabled'])
            <form method="POST" action="{{ route('agents.state', $w['id']) }}">@csrf<input type="hidden" name="action" value="{{ $w['paused'] ? 'resume' : 'pause' }}"><button class="rounded border px-3 py-1">{{ $w['paused'] ? 'Resume schedule' : 'Pause schedule' }}</button></form>
            @endif
            <form method="POST" action="{{ route('agents.state', $w['id']) }}">@csrf<input type="hidden" name="action" value="{{ $w['enabled'] ? 'disable' : 'enable' }}"><button class="rounded border px-3 py-1">{{ $w['enabled'] ? 'Switch off' : 'Switch on' }}</button></form>
            <form method="POST" action="{{ route('agents.duplicate', $w['id']) }}">@csrf<button class="rounded border px-3 py-1">Duplicate</button></form>
            <a href="{{ route('agents.edit', $w['id']) }}" class="rounded border px-3 py-1">Advanced (JSON)</a>
            <form method="POST" action="{{ route('agents.destroy', $w['id']) }}" onsubmit="return confirm('Delete? Its run history will go too.')">@csrf<button class="rounded border border-red-300 px-3 py-1 text-red-700">Delete</button></form>
        </div>
        <p class="mt-2 text-xs text-gray-500">Pause = the daily schedule stops but you can still run it by hand. Switch off = it cannot run at all.</p>
    </section>

    {{-- run + test --}}
    <section class="mt-3 grid gap-3 sm:grid-cols-2" aria-label="Run">
        <form method="POST" action="{{ route('agents.run', $w['id']) }}" class="grid gap-2 rounded-lg bg-white p-4 text-sm shadow">@csrf
            <h3 class="font-semibold">Run now</h3>
            @foreach ($inputs as $in)<label class="text-xs">{{ $in['label'] }}<textarea name="in[{{ $in['key'] }}]" rows="3" maxlength="5000" class="mt-1 w-full rounded border px-2 py-1 text-sm"></textarea></label>@endforeach
            <div><button class="rounded bg-indigo-600 px-3 py-1 text-white" @disabled(! $w['enabled'])>Run now</button>@if (! $w['enabled']) <span class="text-xs text-gray-500">Switch it on first.</span>@endif</div>
        </form>
        <form method="POST" action="{{ route('agents.test', $w['id']) }}" class="grid gap-2 rounded-lg border border-violet-300 bg-violet-50 p-4 text-sm shadow">@csrf
            <h3 class="font-semibold">Test mode</h3>
            <p class="text-xs text-gray-600">Runs the steps one by one and logs each. Steps that only look at your data run for real; anything that writes, sends, waits for you or uses your computer is <b>skipped</b> and only described. Works even when the workflow is off.</p>
            @foreach ($inputs as $in)<label class="text-xs">{{ $in['label'] }}<textarea name="in[{{ $in['key'] }}]" rows="2" maxlength="5000" class="mt-1 w-full rounded border px-2 py-1 text-sm"></textarea></label>@endforeach
            <label class="text-xs"><input type="checkbox" name="simulate_ai" value="1" checked> Do not call the AI (free). Untick to use the real model — it costs credits, up to the limit.</label>
            <div><button class="rounded bg-violet-700 px-3 py-1 text-white">Start test</button></div>
        </form>
    </section>

    {{-- visual builder --}}
    <section class="mt-3 rounded-lg bg-white p-4 text-sm shadow" aria-label="Steps" id="steps-builder">
        <h3 class="font-semibold">Steps</h3>
        <p class="mb-2 text-xs text-gray-500">Drag a step type onto the canvas, drag a step by its title bar to move it, pick a step to edit its prompt, tool or connections. Max 30 steps, no loops, no money tools; anything that sends outside needs an approval step before it.</p>
        <form method="POST" action="{{ route('agents.steps', $w['id']) }}" id="wfb-form">@csrf
            <input type="hidden" name="definition" id="wfb-def" value="{{ json_encode($w['definition'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}">
            <div data-wfb data-input="wfb-def" data-definition="{{ json_encode($w['definition'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}" data-tools="{{ json_encode($toolSpecs, JSON_UNESCAPED_UNICODE) }}"><noscript>The visual builder needs JavaScript. Use “Advanced (JSON)” instead.</noscript></div>
            <div class="mt-2"><button class="rounded bg-indigo-600 px-4 py-1.5 text-white">Save steps</button></div>
        </form>
    </section>

    {{-- settings --}}
    <section class="mt-3 rounded-lg bg-white p-4 text-sm shadow" aria-label="Settings">
        <h3 class="font-semibold">Settings</h3>
        <form method="POST" action="{{ route('agents.settings', $w['id']) }}" class="mt-2 grid gap-2 sm:grid-cols-4">@csrf
            <label class="text-xs">Group<select name="category" class="mt-1 w-full rounded border px-2 py-1 text-sm">
                @unless (isset($categories[$w['category']]))<option value="{{ $w['category'] }}" selected>{{ $allCategories[$w['category']] ?? $w['category'] }} (current)</option>@endunless
                @foreach ($categories as $k => $l)<option value="{{ $k }}" @selected($w['category'] === $k)>{{ $l }}</option>@endforeach</select></label>
            <label class="text-xs">Daily run time (HH:MM, empty = manual only)<input name="schedule" maxlength="5" placeholder="08:00" value="{{ old('schedule', $w['schedule_time']) }}" class="mt-1 w-full rounded border px-2 py-1 text-sm"></label>
            <label class="text-xs">Credit limit per run<input name="max_credits" maxlength="10" value="{{ old('max_credits', $w['max_credits']) }}" class="mt-1 w-full rounded border px-2 py-1 text-sm"></label>
            <label class="text-xs">AI model<select name="model_id" class="mt-1 w-full rounded border px-2 py-1 text-sm"><option value="">Automatic (best for my plan)</option>@foreach ($models as $m)<option value="{{ $m['id'] }}" @selected($w['model_id'] === $m['id'])>{{ $m['name'] }} — {{ $m['provider'] }}</option>@endforeach</select></label>
            <div class="sm:col-span-4"><button class="rounded bg-indigo-600 px-3 py-1 text-white">Save settings</button> <span class="text-xs text-gray-500">Only models your plan allows are listed.</span></div>
        </form>
    </section>

    {{-- desktop --}}
    <section class="mt-3 rounded-lg border border-violet-300 bg-white p-4 text-sm shadow" aria-label="Desktop Agent">
        <h3 class="font-semibold">Desktop Agent</h3>
        <p class="text-xs text-gray-600">Steps that use your computer (“Your computer” tools) never act directly: each one becomes a request to your paired computer. The computer runs it only if its own rules (folders, apps, commands) allow it and you approve it on the <a href="{{ route('pc.index') }}" class="underline">My computer</a> page. Emergency stop and Companion mode apply to workflows too. Test mode never sends them.</p>
        <p class="mt-2 text-xs">Mode: <b>{{ $userMode === 'companion' ? 'Companion (no computer actions)' : 'Agent' }}</b> · Paired computers: <b>{{ count($devices) }}</b>@foreach ($devices as $d) · {{ $d['name'] }} ({{ $d['platform'] }})@endforeach</p>
        @if ($desktopTools !== [])
            <p class="mt-1 text-xs text-violet-700">This workflow uses: {{ implode(', ', $desktopTools) }}.@if (count($devices) === 0) <b>No computer is paired yet — these steps will fail until you pair one.</b>@endif @if ($userMode === 'companion') <b>Companion mode is on — computer steps will be refused.</b>@endif</p>
        @else
            <p class="mt-1 text-xs text-gray-500">This workflow does not use your computer.</p>
        @endif
    </section>

    {{-- history --}}
    <section class="mt-3 rounded-lg bg-white p-4 text-sm shadow" aria-label="Execution history" id="history"><h3 class="font-semibold">Execution history</h3>
        @forelse ($runs as $r)
        <div class="mt-2 rounded border p-2"><div class="flex flex-wrap items-center justify-between gap-2">
            <a href="{{ route('agents.show', ['id' => $w['id'], 'run' => $r['id']]) }}#history" class="font-medium text-indigo-700">{{ $r['created_at'] }}</a>
            <span class="text-xs"><span class="rounded bg-gray-100 px-1">{{ ['schedule' => 'Schedule', 'test' => 'Test'][$r['trigger']] ?? 'Manual' }}</span> {{ $st[$r['status']] ?? $r['status'] }} · {{ number_format($r['spent'], 4) }}/{{ $r['max_credits'] }} credits</span></div>
            @if ($r['error'])<p class="text-xs text-red-700">{{ $r['error'] }}</p>@endif
            @if (in_array($r['status'], ['queued', 'running', 'waiting_approval'], true))
            <form method="POST" action="{{ route('agents.cancel', $r['id']) }}">@csrf<button class="text-xs text-red-700 underline">Stop this run</button></form>@endif
            @if ($openRun === $r['id'])
            <ol class="mt-2 space-y-1 border-t pt-2">@foreach ($steps as $s)
                <li class="text-xs"><b>{{ $s['seq'] }}. {{ $ty[$s['node_type']] ?? $s['node_type'] }} ({{ $s['node_id'] }})</b> — {{ ['ok' => 'OK', 'failed' => 'Failed', 'waiting' => 'Waiting', 'skipped' => 'Skipped (test)'][$s['status']] ?? $s['status'] }}{{ $s['credits'] > 0 ? ' · '.number_format($s['credits'], 4).' Credits' : '' }}
                    @if ($s['output'] !== '')<pre class="mt-1 whitespace-pre-wrap rounded bg-gray-50 p-2">{{ $s['output'] }}</pre>@endif</li>@endforeach</ol>@endif</div>
        @empty<p class="mt-2 text-gray-500">No runs yet.</p>@endforelse
    </section>
    <script src="{{ asset('js/workflow-builder.js') }}" defer></script>
</x-layouts.app>
