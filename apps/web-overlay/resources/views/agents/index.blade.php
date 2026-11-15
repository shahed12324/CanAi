<x-layouts.app title="Agent Builder">
    <header class="jarvis-module-hero jarvis-accent-violet" data-jarvis-module="agents" aria-label="Agent Builder">
      <div class="jarvis-module-hero__identity"><span class="jarvis-module-hero__glyph" aria-hidden="true">✦</span><div>
        <div class="jarvis-module-hero__eyebrow">JARVIS ONE <span aria-hidden="true">/</span> AGENTS</div>
        <h1>Agent Builder</h1>
      </div></div>
      <span class="jarvis-module-hero__section">Agents &amp; Workflows</span>
    </header>

    <div class="space-y-6">
        <p class="text-sm text-gray-600">An agent is a saved set of instructions, a model, the tools it may use and a credit limit. Test it here; use it in workflows. It can never use a tool you did not give it, and anything risky still waits for your approval.</p>
        @if (session('status'))<p class="rounded bg-green-50 px-3 py-2 text-sm text-green-800" role="status">{{ session('status') }}</p>@endif
        @error('agent')<p class="rounded bg-red-50 px-3 py-2 text-sm text-red-800" role="alert">{{ $message }}</p>@enderror

        <section class="grid gap-3 sm:grid-cols-4" aria-label="Usage">
            <div class="rounded-lg bg-white p-3 shadow"><div class="text-xs text-gray-500">Agents</div><div class="text-xl font-semibold">{{ $usage['agents'] }}@if ($limit !== null) <span class="text-sm font-normal text-gray-500">/ {{ (int) $limit }}</span>@endif</div></div>
            <div class="rounded-lg bg-white p-3 shadow"><div class="text-xs text-gray-500">Runs</div><div class="text-xl font-semibold">{{ $usage['runs'] }}</div></div>
            <div class="rounded-lg bg-white p-3 shadow"><div class="text-xs text-gray-500">Failed</div><div class="text-xl font-semibold">{{ $usage['failed'] }}</div></div>
            <div class="rounded-lg bg-white p-3 shadow"><div class="text-xs text-gray-500">Credits used</div><div class="text-xl font-semibold">{{ $usage['credits'] }}</div></div>
        </section>

        <div class="flex justify-end"><a href="{{ route('agentbuilder.create') }}" class="rounded bg-indigo-600 px-3 py-1.5 text-sm text-white">New agent</a></div>

        @if (count($agents) === 0)
            <p class="rounded-lg bg-white p-4 text-sm text-gray-600 shadow">No agents yet. Create your first one.</p>
        @else
            <ul class="space-y-2">
            @foreach ($agents as $a)
                <li class="rounded-lg bg-white p-4 shadow">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <div>
                            <a class="font-medium text-indigo-700" href="{{ route('agentbuilder.edit', $a['id']) }}">{{ $a['name'] }}</a>
                            <span class="ml-2 text-xs text-gray-500">v{{ $a['version'] }} · {{ $a['enabled'] ? 'On' : 'Off' }} · {{ count($a['tools']) }} tool(s)</span>
                            @if ($a['description'] !== '')<p class="text-sm text-gray-600">{{ $a['description'] }}</p>@endif
                        </div>
                        <form method="POST" action="{{ route('agentbuilder.destroy', $a['id']) }}" onsubmit="return confirm('Delete this agent and its history?')">@csrf @method('DELETE')<button class="rounded border px-2 py-1 text-xs text-red-700">Delete</button></form>
                    </div>
                </li>
            @endforeach
            </ul>
        @endif
    </div>
</x-layouts.app>
