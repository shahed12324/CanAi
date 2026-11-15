@php($edit = $agent !== null)
@php($v = fn (string $k, $d = '') => old($k, $edit ? ($agent[$k] ?? $d) : $d))
@php($chosen = old('tools', $edit ? $agent['tools'] : []))
<x-layouts.app :title="$edit ? 'Edit agent' : 'New agent'">
    <header class="jarvis-module-hero jarvis-accent-violet" data-jarvis-module="agents" aria-label="Agent Builder">
      <div class="jarvis-module-hero__identity"><span class="jarvis-module-hero__glyph" aria-hidden="true">✦</span><div>
        <div class="jarvis-module-hero__eyebrow">JARVIS ONE <span aria-hidden="true">/</span> AGENTS</div>
        <h1>{{ $edit ? $agent['name'] : 'New agent' }}</h1>
      </div></div>
      <span class="jarvis-module-hero__section">Agents &amp; Workflows</span>
    </header>

    <div class="space-y-6">
        <a href="{{ route('agentbuilder.index') }}" class="text-sm text-indigo-700">← All agents</a>
        @if (session('status'))<p class="rounded bg-green-50 px-3 py-2 text-sm text-green-800" role="status">{{ session('status') }}</p>@endif
        @error('agent')<p class="rounded bg-red-50 px-3 py-2 text-sm text-red-800" role="alert">{{ $message }}</p>@enderror

        <form method="POST" action="{{ $edit ? route('agentbuilder.update', $agent['id']) : route('agentbuilder.store') }}" class="space-y-4 rounded-lg bg-white p-4 shadow">
            @csrf @if ($edit) @method('PUT') @endif
            <div><label class="text-sm font-medium" for="name">Name</label><input id="name" name="name" maxlength="80" required value="{{ $v('name') }}" class="mt-1 w-full rounded border px-2 py-1"></div>
            <div><label class="text-sm font-medium" for="description">What it is for</label><input id="description" name="description" maxlength="500" value="{{ $v('description') }}" class="mt-1 w-full rounded border px-2 py-1"></div>
            <div><label class="text-sm font-medium" for="system_prompt">Instructions (system prompt)</label><textarea id="system_prompt" name="system_prompt" rows="8" maxlength="8000" required class="mt-1 w-full rounded border px-2 py-1">{{ $v('system_prompt') }}</textarea></div>

            <div class="grid gap-3 sm:grid-cols-2">
                <div><label class="text-sm font-medium" for="model_id">Model</label>
                    <select id="model_id" name="model_id" class="mt-1 w-full rounded border px-2 py-1">
                        <option value="">Automatic (best for my plan)</option>
                        @foreach ($models as $m)<option value="{{ $m['id'] }}" @selected($v('model_id') === $m['id'])>{{ $m['name'] }} — {{ $m['provider'] }}</option>@endforeach
                    </select><p class="mt-1 text-xs text-gray-500">Only models your plan allows. If the chosen one is not available later, the automatic one is used.</p></div>
                <div><label class="text-sm font-medium" for="max_credits">Credit limit per run</label><input id="max_credits" name="max_credits" type="number" step="0.01" min="0" max="1000" value="{{ $v('max_credits', 2) }}" class="mt-1 w-full rounded border px-2 py-1"></div>
                <div><label class="text-sm font-medium" for="temperature">Creativity (0–2)</label><input id="temperature" name="temperature" type="number" step="0.1" min="0" max="2" value="{{ $v('temperature', 0.3) }}" class="mt-1 w-full rounded border px-2 py-1"></div>
                <div><label class="text-sm font-medium" for="max_tokens">Max answer length (tokens)</label><input id="max_tokens" name="max_tokens" type="number" min="100" max="4000" value="{{ $v('max_tokens', 800) }}" class="mt-1 w-full rounded border px-2 py-1"></div>
            </div>

            <fieldset><legend class="text-sm font-medium">Permissions</legend>
                <label class="block text-sm"><input type="checkbox" name="perm_allow_external" value="1" @checked(old('perm_allow_external', $edit ? ($agent['permissions']['allow_external'] ?? false) : false))> May act outside the app (every use needs your approval)</label>
                <label class="block text-sm"><input type="checkbox" name="perm_desktop" value="1" @checked(old('perm_desktop', $edit ? ($agent['permissions']['desktop'] ?? false) : false))> May use my paired computer (still limited by the computer's own rules and your approval)</label>
                <label class="block text-sm"><input type="checkbox" name="use_knowledge" value="1" @checked($v('use_knowledge', false))> May read the workspace knowledge base</label>
                <label class="block text-sm"><input type="checkbox" name="use_memory" value="1" @checked($v('use_memory', false))> May use my saved memories</label>
                @if ($edit)<label class="block text-sm"><input type="checkbox" name="enabled" value="1" @checked($v('enabled', true))> Agent is switched on</label>@endif
            </fieldset>

            <fieldset><legend class="text-sm font-medium">Tools</legend>
                <p class="text-xs text-gray-500">Tools that move money are never offered. Tools that act outside the app need the permission above.</p>
                <div class="mt-1 grid gap-1 sm:grid-cols-2">
                @foreach ($toolList as $k => $t)@continue($t['risk'] === 'money')
                    <label class="text-sm"><input type="checkbox" name="tools[]" value="{{ $k }}" @checked(in_array($k, (array) $chosen, true))> {{ $t['label'] }} <span class="text-xs text-gray-500">({{ $k }} · {{ $t['risk'] }})</span></label>
                @endforeach
                </div>
            </fieldset>
            <button class="rounded bg-indigo-600 px-3 py-1.5 text-sm text-white">{{ $edit ? 'Save changes' : 'Create agent' }}</button>
        </form>

        @if ($edit)
        <section class="rounded-lg bg-white p-4 shadow" aria-label="Test">
            <h2 class="mb-1 text-sm font-semibold">Test this agent</h2>
            <p class="mb-2 text-xs text-gray-500">Talks to the real model and uses credits (up to the limit above). No tools run in a test.</p>
            <form method="POST" action="{{ route('agentbuilder.test', $agent['id']) }}" class="space-y-2">@csrf
                <textarea name="message" rows="3" maxlength="4000" required class="w-full rounded border px-2 py-1" aria-label="Test message">{{ old('message') }}</textarea>
                <button class="rounded bg-gray-800 px-3 py-1 text-sm text-white">Run test</button>
            </form>
            @if (session('test'))
                <div class="mt-3 rounded border border-indigo-200 bg-indigo-50 p-3 text-sm"><div class="whitespace-pre-wrap">{{ session('test')['text'] }}</div><div class="mt-2 text-xs text-gray-500">{{ session('test')['model'] }} · {{ session('test')['credits'] }} credits</div></div>
            @endif
            <details class="mt-3 text-xs text-gray-600"><summary>Exactly what the model is told</summary><pre class="mt-1 whitespace-pre-wrap">{{ $systemPrompt }}</pre></details>
        </section>

        <section class="rounded-lg bg-white p-4 shadow" aria-label="Versions">
            <h2 class="mb-2 text-sm font-semibold">Version history</h2>
            <ul class="space-y-1 text-sm">
            @foreach ($versions as $ver)
                <li class="flex items-center justify-between"><span>v{{ $ver['version'] }} · {{ $ver['changed_at'] }}</span>
                    @if ($ver['version'] !== $agent['version'])<form method="POST" action="{{ route('agentbuilder.restore', $agent['id']) }}">@csrf<input type="hidden" name="version" value="{{ $ver['version'] }}"><button class="rounded border px-2 py-0.5 text-xs">Restore</button></form>@else<span class="text-xs text-gray-500">current</span>@endif</li>
            @endforeach
            </ul>
        </section>

        <section class="rounded-lg bg-white p-4 shadow" aria-label="Recent runs">
            <h2 class="mb-2 text-sm font-semibold">Recent runs</h2>
            @forelse ($runs as $r)
                <div class="border-t py-2 text-sm"><span class="text-xs text-gray-500">{{ $r['created_at'] }} · {{ $r['kind'] }} · {{ $r['status'] }} · {{ $r['credits'] }} credits · v{{ $r['agent_version'] }}</span><div class="truncate">{{ \Illuminate\Support\Str::limit($r['input'], 120) }}</div></div>
            @empty<p class="text-sm text-gray-600">No runs yet.</p>@endforelse
        </section>
        @endif
    </div>
</x-layouts.app>
