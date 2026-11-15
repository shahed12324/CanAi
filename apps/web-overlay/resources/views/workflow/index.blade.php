<x-layouts.app title="Agents and workflows">
    {{-- JARVIS ONE: page-specific visual identity; existing actions preserved --}}
    <header class="jarvis-module-hero jarvis-accent-violet" data-jarvis-module="workflow" aria-label="Agents and workflows">
      <div class="jarvis-module-hero__identity"><span class="jarvis-module-hero__glyph" aria-hidden="true">✦</span><div>
        <div class="jarvis-module-hero__eyebrow">JARVIS ONE <span aria-hidden="true">/</span> WORKFLOW</div>
        <h1>Agents and workflows</h1>
      </div></div>
      <span class="jarvis-module-hero__section">Workflow Workspace</span>
    </header>

    @php($st = ['queued' => 'In queue', 'running' => 'Running', 'waiting_approval' => 'Awaiting approval', 'done' => 'End', 'failed' => 'Failed', 'cancelled' => 'Closed'])
    @php($ty = ['ai' => 'AI', 'tool' => 'Tool', 'condition' => 'Condition', 'approval' => 'Approval', 'delay' => 'Wait', 'parallel' => 'Parallel', 'output' => 'Results'])
    @php($badge = ['active' => 'bg-green-100 text-green-800', 'scheduled' => 'bg-blue-100 text-blue-800', 'running' => 'bg-violet-100 text-violet-800', 'paused' => 'bg-amber-100 text-amber-800', 'disabled' => 'bg-gray-200 text-gray-700', 'attention' => 'bg-red-100 text-red-800'])
    <h2 class="text-xl font-semibold">Agents and workflows</h2>
    <p class="mt-1 rounded bg-amber-50 p-3 text-sm text-amber-800">A workflow = a job that runs step by step (AI, tools, conditions, approval). Every step is logged below. Workflows work on your own data (tasks, notes, knowledge base, notifying yourself) and, only if you add those steps, on your paired computer — every computer action still needs the computer's own permission and your approval. AI steps cost credits; the run stops when the per-run credit limit is reached. Runs execute in the background and can take up to a minute to start. Verify AI text. <a href="{{ route('agentbuilder.index') }}" class="underline">Agent Builder</a></p>
    @if ($errors->any())<p class="mt-2 rounded bg-red-50 p-3 text-sm text-red-700" role="alert">{{ $errors->first() }}</p>@endif
    @if (session('status'))<p class="mt-2 rounded bg-green-50 p-3 text-sm text-green-700" role="status">{{ session('status') }}</p>@endif

    @if ($pending !== [])
    <section class="mt-3 rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm"><h3 class="font-semibold">Needs your approval</h3>
        @foreach ($pending as $p)
        <div class="mt-2 rounded bg-white p-3"><p class="font-medium">{{ $p['workflow_name'] }}</p><p class="whitespace-pre-line">{{ $p['message'] }}</p>
            <div class="mt-2 flex gap-2">
                <form method="POST" action="{{ route('agents.decide', $p['run_id']) }}">@csrf<input type="hidden" name="approve" value="1"><button class="rounded bg-green-600 px-3 py-1 text-white">Approve</button></form>
                <form method="POST" action="{{ route('agents.decide', $p['run_id']) }}">@csrf<input type="hidden" name="approve" value="0"><button class="rounded bg-gray-500 px-3 py-1 text-white">Cancel</button></form></div></div>@endforeach
    </section>
    @endif

    {{-- ===== My workflows ===== --}}
    <section class="mt-3 rounded-lg bg-white p-4 text-sm shadow" id="mine"><h3 class="font-semibold">My workflows ({{ count($cards) }}@if ($total !== count($cards)) of {{ $total }}@endif)</h3>
        <form method="GET" action="{{ route('agents.index') }}" class="mt-2 flex flex-wrap items-end gap-2" role="search" aria-label="Search my workflows">
            <input type="hidden" name="tq" value="{{ $f['tq'] }}"><input type="hidden" name="tgroup" value="{{ $f['tgroup'] }}">
            <label class="text-xs">Search<input type="search" name="q" value="{{ $f['q'] }}" maxlength="80" placeholder="Name or description" class="mt-1 block rounded border px-2 py-1 text-sm"></label>
            <label class="text-xs">Group<select name="group" class="mt-1 block rounded border px-2 py-1 text-sm"><option value="">All</option>@foreach ($groups as $k => $l)<option value="{{ $k }}" @selected($f['group'] === $k)>{{ $l }}</option>@endforeach</select></label>
            <label class="text-xs">Status<select name="status" class="mt-1 block rounded border px-2 py-1 text-sm">@foreach ($statuses as $k => $l)<option value="{{ $k === 'all' ? '' : $k }}" @selected($f['status'] === ($k === 'all' ? '' : $k))>{{ $l }}</option>@endforeach</select></label>
            <button class="rounded bg-indigo-600 px-3 py-1 text-white">Filter</button>
            @if ($f['q'] !== '' || $f['group'] !== '' || $f['status'] !== '')<a href="{{ route('agents.index', ['tq' => $f['tq'], 'tgroup' => $f['tgroup']]) }}" class="text-xs text-indigo-700 underline">Clear</a>@endif
        </form>

        @forelse ($cards as $w)
        <div class="mt-2 rounded border p-3" data-workflow="{{ $w['id'] }}"><div class="flex flex-wrap items-center justify-between gap-2">
            <p class="font-medium"><a href="{{ route('agents.show', $w['id']) }}" class="text-indigo-700">{{ $w['name'] }}</a> <span class="text-xs text-gray-500">· {{ $groups[$w['group']] ?? '' }} · v{{ $w['version'] }}{{ $w['schedule_time'] ? ' · Daily '.$w['schedule_time'] : '' }} · Limit {{ $w['max_credits'] }} Credits</span></p>
            <span class="rounded-full px-2 py-0.5 text-xs {{ $badge[$w['status']] ?? '' }}" data-status="{{ $w['status'] }}">{{ $statuses[$w['status']] ?? $w['status'] }}</span></div>
            @if ($w['description'] !== '')<p class="text-xs text-gray-600">{{ $w['description'] }}</p>@endif
            @if ($w['uses_desktop'])<p class="text-xs text-violet-700">Uses your paired computer — only with its permission and your approval.</p>@endif
            @if ($w['last_run'])<p class="text-xs text-gray-500">Last run: {{ $st[$w['last_run']['status']] ?? $w['last_run']['status'] }} · {{ $w['last_run']['created_at'] }}</p>@endif
            <form method="POST" action="{{ route('agents.run', $w['id']) }}" class="mt-2 grid gap-2">@csrf
                @foreach ($inputs[$w['id']] ?? [] as $in)<label class="text-xs">{{ $in['label'] }}<textarea name="in[{{ $in['key'] }}]" rows="3" maxlength="5000" class="mt-1 w-full rounded border px-2 py-1 text-sm"></textarea></label>@endforeach
                <div class="flex flex-wrap gap-2"><button class="rounded bg-indigo-600 px-3 py-1 text-white" @disabled(! $w['enabled'])>Run now</button>
                    <a href="{{ route('agents.show', $w['id']) }}" class="rounded border px-3 py-1">Open</a></div></form>
            <div class="mt-1 flex flex-wrap gap-3">
                @if ($w['enabled'])
                <form method="POST" action="{{ route('agents.state', $w['id']) }}">@csrf<input type="hidden" name="action" value="{{ $w['paused'] ? 'resume' : 'pause' }}"><button class="text-xs text-indigo-700 underline">{{ $w['paused'] ? 'Resume schedule' : 'Pause schedule' }}</button></form>
                @endif
                <form method="POST" action="{{ route('agents.state', $w['id']) }}">@csrf<input type="hidden" name="action" value="{{ $w['enabled'] ? 'disable' : 'enable' }}"><button class="text-xs text-indigo-700 underline">{{ $w['enabled'] ? 'Switch off' : 'Switch on' }}</button></form>
                <form method="POST" action="{{ route('agents.duplicate', $w['id']) }}">@csrf<button class="text-xs text-indigo-700 underline">Duplicate</button></form>
                <form method="POST" action="{{ route('agents.destroy', $w['id']) }}" onsubmit="return confirm('Delete? Its run history will go too.')">@csrf<button class="text-xs text-red-700 underline">Delete</button></form></div></div>
        @empty<p class="mt-2 text-gray-500">{{ $total === 0 ? 'Nothing yet — add from the templates below or start from scratch.' : 'No workflow matches this search or filter.' }}</p>@endforelse

        <form method="POST" action="{{ route('agents.create') }}" class="mt-4 grid gap-2 rounded border border-dashed p-3 sm:grid-cols-4" aria-label="New workflow from scratch">@csrf
            <p class="font-medium sm:col-span-4">Start from scratch</p>
            <label class="text-xs sm:col-span-2">Name<input name="name" required maxlength="120" value="{{ old('name') }}" class="mt-1 w-full rounded border px-2 py-1 text-sm"></label>
            <label class="text-xs">Group<select name="category" class="mt-1 w-full rounded border px-2 py-1 text-sm">@foreach ($categories as $k => $l)<option value="{{ $k }}" @selected(old('category') === $k)>{{ $l }}</option>@endforeach</select></label>
            <label class="text-xs sm:col-span-3">Description (optional)<input name="description" maxlength="500" value="{{ old('description') }}" class="mt-1 w-full rounded border px-2 py-1 text-sm"></label>
            <div class="flex items-end"><button class="rounded bg-indigo-600 px-3 py-1 text-white">Create and open builder</button></div>
        </form>
    </section>

    {{-- ===== Templates by group ===== --}}
    <section class="mt-3 rounded-lg bg-white p-4 text-sm shadow" id="templates"><h3 class="font-semibold">Start from a ready template</h3>
        <nav class="mt-2 flex flex-wrap gap-2" aria-label="Template groups">
            <a href="{{ route('agents.index', ['q' => $f['q'], 'group' => $f['group'], 'status' => $f['status'], 'tq' => $f['tq']]) }}#templates" class="rounded-full border px-3 py-0.5 text-xs {{ $f['tgroup'] === '' ? 'bg-indigo-600 text-white' : '' }}">All ({{ array_sum($templateCounts) }})</a>
            @foreach ($groups as $k => $l)<a href="{{ route('agents.index', ['q' => $f['q'], 'group' => $f['group'], 'status' => $f['status'], 'tq' => $f['tq'], 'tgroup' => $k]) }}#templates" class="rounded-full border px-3 py-0.5 text-xs {{ $f['tgroup'] === $k ? 'bg-indigo-600 text-white' : '' }}">{{ $l }} ({{ $templateCounts[$k] }})</a>@endforeach
        </nav>
        <form method="GET" action="{{ route('agents.index') }}#templates" class="mt-2 flex gap-2" role="search" aria-label="Search templates">
            <input type="hidden" name="q" value="{{ $f['q'] }}"><input type="hidden" name="group" value="{{ $f['group'] }}"><input type="hidden" name="status" value="{{ $f['status'] }}"><input type="hidden" name="tgroup" value="{{ $f['tgroup'] }}">
            <input type="search" name="tq" value="{{ $f['tq'] }}" maxlength="80" placeholder="Search templates" class="rounded border px-2 py-1 text-sm" aria-label="Search templates"><button class="rounded border px-3 py-1">Search</button></form>
        <div class="mt-2 grid gap-2 sm:grid-cols-2">@forelse ($templates as $k => $t)
            <form method="POST" action="{{ route('agents.template') }}" class="rounded border p-3" data-template="{{ $k }}">@csrf<input type="hidden" name="template" value="{{ $k }}">
                <p class="font-medium">{{ $t['name'] }} <span class="rounded-full bg-gray-100 px-2 text-xs text-gray-600">{{ $groups[\App\Workflow\Templates::group($k)] }}</span></p><p class="text-xs text-gray-600">{{ $t['description'] }}</p>
                <p class="mt-1 text-xs text-gray-500"> max {{ $t['max_credits'] }} Credits/run {{ $t['schedule'] ? ' · Daily '.$t['schedule'] : '' }}</p>
                <button class="mt-2 rounded bg-indigo-600 px-3 py-1 text-white">Add to my list</button></form>
            @empty<p class="text-gray-500 sm:col-span-2">No template matches.</p>@endforelse</div>
    </section>

    <section class="mt-3 rounded-lg bg-white p-4 text-sm shadow"><h3 class="font-semibold">Recent runs</h3>
        @forelse ($runs as $r)
        <div class="mt-2 rounded border p-2"><div class="flex flex-wrap items-center justify-between gap-2">
            <a href="{{ route('agents.show', ['id' => $r['workflow_id'], 'run' => $r['id']]) }}" class="font-medium text-indigo-700">{{ $r['workflow_name'] }}</a>
            <span class="text-xs">{{ $st[$r['status']] ?? $r['status'] }} · {{ ['schedule' => 'Schedule', 'test' => 'Test'][$r['trigger']] ?? 'Manual' }} · {{ number_format($r['spent'], 4) }}/{{ $r['max_credits'] }} credits · {{ $r['created_at'] }}</span></div>
            @if ($r['error'])<p class="text-xs text-red-700">{{ $r['error'] }}</p>@endif
            @if (in_array($r['status'], ['queued', 'running', 'waiting_approval'], true))
            <form method="POST" action="{{ route('agents.cancel', $r['id']) }}">@csrf<button class="text-xs text-red-700 underline">Stop this run</button></form>@endif
            @if ($openRun === $r['id'])
            <ol class="mt-2 space-y-1 border-t pt-2">@foreach ($steps as $s)
                <li class="text-xs"><b>{{ $s['seq'] }}. {{ $ty[$s['node_type']] ?? $s['node_type'] }} ({{ $s['node_id'] }})</b> — {{ ['ok' => 'OK', 'failed' => 'Failed', 'waiting' => 'Waiting', 'skipped' => 'Skipped (test)'][$s['status']] ?? $s['status'] }}{{ $s['credits'] > 0 ? ' · '.number_format($s['credits'], 4).' Credits' : '' }}
                    @if ($s['output'] !== '')<pre class="mt-1 whitespace-pre-wrap rounded bg-gray-50 p-2">{{ $s['output'] }}</pre>@endif</li>@endforeach</ol>@endif</div>
        @empty<p class="mt-2 text-gray-500">No runs yet.</p>@endforelse
    </section>
</x-layouts.app>
