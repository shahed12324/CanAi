<x-layouts.app title="My computer">
    {{-- JARVIS ONE: page-specific visual identity; existing actions preserved --}}
    <header class="jarvis-module-hero jarvis-accent-violet" data-jarvis-module="pc" aria-label="My computer">
      <div class="jarvis-module-hero__identity"><span class="jarvis-module-hero__glyph" aria-hidden="true">✦</span><div>
        <div class="jarvis-module-hero__eyebrow">JARVIS ONE <span aria-hidden="true">/</span> PC</div>
        <h1>My computer</h1>
      </div></div>
      <span class="jarvis-module-hero__section">Pc Workspace</span>
    </header>

    @php($risk = ['low' => 'Low', 'medium' => 'Medium', 'high' => 'High', 'critical' => 'Critical'])
    @php($st = ['blocked' => 'Held', 'pending' => 'Waiting', 'approved' => 'Allowed', 'rejected' => 'Cancel', 'running' => 'Running', 'done' => 'End', 'failed' => 'Failed', 'expired' => 'Expired', 'stopped' => 'Stopped'])
    <div class="space-y-6">
        <header>
            <h1 class="text-lg font-semibold">My computer</h1>
            <p class="text-sm text-gray-600">Connect the desktop app, decide which folders/apps/commands/sites may be touched, and approve risky jobs yourself. Nothing runs without your permission; the emergency stop halts everything.</p>
        </header>
        @if (session('status'))<p class="rounded bg-green-50 px-3 py-2 text-sm text-green-800" role="status">{{ session('status') }}</p>@endif
        @error('pc')<p class="rounded bg-red-50 px-3 py-2 text-sm text-red-800" role="alert">{{ $message }}</p>@enderror

        @if ($pairCode)
            <section class="rounded-lg bg-white p-4 shadow">
            <h2 class="mb-1 text-sm font-semibold">Mode</h2>
            <p class="mb-2 text-xs text-gray-500">Companion: chat and voice only — nothing can be done on your computer. Agent: your computer can do approved jobs under the rules below.</p>
            <form method="POST" action="{{ route('companion.mode') }}" class="flex flex-wrap items-center gap-2">@csrf
                <span class="text-sm">Now: <strong>{{ ($userMode ?? 'agent') === 'companion' ? 'Companion' : 'Agent' }}</strong></span>
                @if (($userMode ?? 'agent') === 'companion')
                    <button name="mode" value="agent" class="rounded border px-3 py-1 text-sm">Switch to Agent mode</button>
                @else
                    <button name="mode" value="companion" class="rounded bg-gray-800 px-3 py-1 text-sm text-white">Switch to Companion mode</button>
                @endif
            </form>
        </section>

        <section class="rounded-lg border-2 border-indigo-300 bg-indigo-50 p-4" role="alert">
                <p class="text-sm">Enter this code in the desktop app (10 minutes, shown only once):</p>
                <p class="my-2 font-mono text-2xl tracking-widest">{{ $pairCode }}</p>
            </section>
        @endif

        @foreach ($devices as $d)
            <section class="space-y-3 rounded-lg bg-white p-4 shadow">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h2 class="font-medium">{{ $d['name'] }} <span class="text-xs text-gray-500">({{ $d['platform'] }}) · Last seen: {{ $d['last_seen_at'] ?? 'Never' }}</span></h2>
                    <form method="POST" action="{{ route('pc.revoke', $d['id']) }}" onsubmit="return confirm('Remove the device? Its open jobs will stop.')">@csrf @method('DELETE')<x-button variant="ghost">Remove</x-button></form>
                </div>

                @if ($d['metric'])
                    <p class="text-sm text-gray-700">CPU {{ $d['metric']['cpu'] }}% · RAM {{ $d['metric']['mem'] }}% · Disk {{ $d['metric']['disk'] }}%@if ($d['metric']['gpu'] !== null) · GPU {{ $d['metric']['gpu'] }}%@endif @if ($d['metric']['battery'] !== null) · Battery {{ $d['metric']['battery'] }}%@endif @if ($d['metric']['temp_c'] !== null) · {{ $d['metric']['temp_c'] }}°C @endif <span class="text-xs text-gray-500">({{ $d['metric']['created_at'] }})</span></p>
                    @foreach ($d['alerts'] as $a)<p class="rounded px-2 py-1 text-sm {{ $a['level'] === 'critical' ? 'bg-red-50 text-red-800' : ($a['level'] === 'warn' ? 'bg-yellow-50 text-yellow-800' : 'bg-gray-50 text-gray-700') }}">{{ $a['message'] }}</p>@endforeach
                @else
                    <p class="text-sm text-gray-500">No monitor data has arrived yet.</p>
                @endif

                <details>
                    <summary class="cursor-pointer text-sm font-medium">Rules (what may be touched)</summary>
                    <form method="POST" action="{{ route('pc.policy', $d['id']) }}" class="mt-2 grid gap-2 text-sm">
                        @csrf @method('PUT')
                        <label class="grid gap-1">Allowed folders (one per line; not the whole drive)<textarea name="roots" rows="3" class="rounded border border-gray-300 px-2 py-1 font-mono">{{ implode("\n", $d['policy']['roots']) }}</textarea></label>
                        <label class="grid gap-1">Allowed apps<textarea name="apps" rows="2" class="rounded border border-gray-300 px-2 py-1">{{ implode("\n", $d['policy']['apps']) }}</textarea></label>
                        <label class="grid gap-1">Allowed commands (program name only, for example git)<textarea name="commands" rows="2" class="rounded border border-gray-300 px-2 py-1 font-mono">{{ implode("\n", $d['policy']['commands']) }}</textarea></label>
                        <label class="grid gap-1">Allowed sites (https; including subdomains)<textarea name="hosts" rows="2" class="rounded border border-gray-300 px-2 py-1 font-mono">{{ implode("\n", $d['policy']['hosts']) }}</textarea></label>
                        <fieldset class="grid gap-1">
                            <legend class="font-medium">By job type</legend>
                            @foreach ($kinds as $k => [$label, $rk])
                                @php($cur = $d['policy']['modes'][$k] ?? 'ask')
                                <label class="flex items-center justify-between gap-2"><span>{{ $label }} <span class="text-xs text-gray-500">(Risk: {{ $risk[$rk] }})</span></span>
                                    <select name="modes[{{ $k }}]" class="rounded border border-gray-300 px-2 py-1">
                                        <option value="" @selected($cur === 'ask')>Ask every time</option>
                                        @if (in_array($rk, ['low', 'medium'], true))<option value="allow" @selected($cur === 'allow')>Automatic (within scope)</option>@endif
                                        <option value="deny" @selected($cur === 'deny')>Denied</option>
                                    </select></label>
                            @endforeach
                        </fieldset>
                        <div><x-button>Save rules</x-button></div>
                    </form>
                </details>

                <details>
                    <summary class="cursor-pointer text-sm font-medium">Send a job request</summary>
                    <form method="POST" action="{{ route('pc.request', $d['id']) }}" class="mt-2 grid gap-2 text-sm sm:grid-cols-[14rem_1fr_auto]">
                        @csrf
                        <select name="kind" class="rounded border border-gray-300 px-2 py-1">@foreach ($kinds as $k => [$label, $rk])<option value="{{ $k }}">{{ $label }}</option>@endforeach</select>
                        <input name="target" required maxlength="2000" placeholder="path / app / command with arguments / https://… / volume=30 / restart" class="rounded border border-gray-300 px-2 py-1 font-mono">
                        <x-button>Send</x-button>
                    </form>
                </details>
            </section>
        @endforeach

        <section class="rounded-lg bg-white p-4 shadow">
            <h2 class="mb-2 text-sm font-semibold">Connect a new computer</h2>
            <form method="POST" action="{{ route('pc.pair') }}" class="flex flex-wrap gap-2 text-sm">
                @csrf
                <input name="name" required maxlength="60" placeholder="Name (for example office laptop)" value="{{ old('name') }}" class="rounded border border-gray-300 px-2 py-1">
                <select name="platform" class="rounded border border-gray-300 px-2 py-1"><option value="windows">Windows</option><option value="macos">macOS</option><option value="linux">Linux</option></select>
                <x-button>Generate code</x-button>
            </form>
        </section>

        <section class="rounded-lg bg-white p-4 shadow">
            <h2 class="mb-2 text-sm font-semibold">Recent jobs</h2>
            @forelse ($actions as $a)
                <div class="flex flex-wrap items-center justify-between gap-2 border-t py-2 text-sm first:border-t-0">
                    <div>
                        <span class="font-medium">{{ $kinds[$a['kind']][0] ?? $a['kind'] }}</span> — {{ $a['device'] }}
                        <span class="text-xs text-gray-500">Risk: {{ $risk[$a['risk']] ?? $a['risk'] }} · {{ $st[$a['status']] ?? $a['status'] }}@if ($a['reason']) ({{ \App\Pc\PcService::REASONS[$a['reason']] ?? $a['reason'] }})@endif</span>
                        <div class="break-all font-mono text-xs text-gray-600">{{ json_encode($a['params'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</div>
                        @if ($a['result'])<div class="text-xs text-gray-500">Result: {{ \Illuminate\Support\Str::limit($a['result'], 200) }}</div>@endif
                    </div>
                    @if ($a['status'] === 'pending')
                        <form method="POST" action="{{ route('pc.decide', $a['id']) }}" class="flex gap-2">@csrf
                            @if (in_array($a['risk'], ['high', 'critical'], true))
                                <span class="self-center text-xs text-amber-700">High risk — approve with PIN in the mobile app</span>
                            @else
                                <button name="decision" value="approve" class="rounded bg-green-600 px-3 py-1 text-white">Approval</button>
                            @endif
                            <button name="decision" value="reject" class="rounded border px-3 py-1">Cancel</button>
                        </form>
                    @endif
                </div>
            @empty
                <p class="text-sm text-gray-500">No jobs yet.</p>
            @endforelse
        </section>
        <section class="rounded-lg bg-white p-4 shadow">
            <h2 class="mb-1 text-sm font-semibold">Plans (several steps, approved by you)</h2>
            <p class="mb-3 text-xs text-gray-500">Paste a plan (from the planner, or a plan file). Every step is checked against the device rules. You approve the plan; high-risk steps (move, trash, delete) are approved separately on your phone with your PIN. The computer verifies the signature and checks its own rules again before it does anything.</p>
            @foreach ($devices as $d)
                <form method="POST" action="{{ route('pc.plan.draft', $d['id']) }}" class="mb-3 grid gap-2 rounded border border-indigo-200 bg-indigo-50 p-2">@csrf
                    <div class="text-xs text-gray-600">{{ $d['name'] }} — let the assistant draft a plan (costs credits; nothing runs until you approve)</div>
                    <input name="goal" maxlength="2000" minlength="3" required placeholder="e.g. Put my invoices from the Downloads folder into a new folder called March" class="rounded border px-2 py-1 text-sm">
                    <div><button class="rounded bg-indigo-600 px-3 py-1 text-sm text-white">Draft a plan</button></div>
                </form>
                <form method="POST" action="{{ route('pc.plan.submit', $d['id']) }}" class="mb-3 grid gap-2">@csrf
                    <div class="text-xs text-gray-600">{{ $d['name'] }}</div>
                    <input name="goal" maxlength="2000" required placeholder="What should it do? (a short sentence)" class="rounded border px-2 py-1 text-sm">
                    <textarea name="plan" rows="4" required placeholder='{"steps":[{"id":"s1","kind":"make_dir","params":{"path":"..."}}]}' class="rounded border px-2 py-1 font-mono text-xs"></textarea>
                    <div><button class="rounded bg-gray-800 px-3 py-1 text-sm text-white">Submit plan</button></div>
                </form>
            @endforeach
            @forelse ($plans ?? [] as $p)
                <div class="border-t py-2 text-sm first:border-t-0">
                    <div><span class="font-medium">{{ $p['goal'] }}</span> — {{ $p['device'] }}
                        <span class="text-xs text-gray-500">{{ $p['status'] }}@if ($p['reason']) ({{ \App\Pc\PcService::planReason($p['reason']) }})@endif</span></div>
                    <ol class="ml-5 list-decimal text-xs text-gray-600">
                        @foreach ($p['steps'] as $stp)
                            <li><span class="font-mono">{{ $stp['kind'] }}</span> {{ \Illuminate\Support\Str::limit($stp['target'], 120) }}@if (in_array($stp['id'], $p['needs'], true)) <span class="text-amber-700">— needs its own approval</span>@endif</li>
                        @endforeach
                    </ol>
                    @if ($p['result'])<div class="text-xs text-gray-500">Result: {{ \Illuminate\Support\Str::limit($p['result'], 300) }}</div>@endif
                    @if (in_array($p['status'], ['pending', 'approved'], true))
                        <div class="mt-1 flex flex-wrap items-center gap-2">
                            @if ($p['status'] === 'pending')
                                <form method="POST" action="{{ route('pc.plan.decide', $p['id']) }}" class="flex gap-2">@csrf
                                    <button name="decision" value="approve" class="rounded bg-green-600 px-3 py-1 text-white">Approve plan</button>
                                    <button name="decision" value="reject" class="rounded border px-3 py-1">Cancel</button>
                                </form>
                            @endif
                            @foreach ($p['needs'] as $needStep)
                                @php($stepKind = collect($p['steps'])->firstWhere('id', $needStep)['kind'] ?? '')
                                @if (in_array(\App\Pc\PcService::KINDS[$stepKind][1] ?? 'high', ['high', 'critical'], true))
                                    <span class="text-xs text-amber-700">Step {{ $needStep }}: approve in the mobile app with your PIN</span>
                                @else
                                    <form method="POST" action="{{ route('pc.plan.step', $p['id']) }}">@csrf
                                        <input type="hidden" name="step" value="{{ $needStep }}">
                                        <button class="rounded border px-2 py-1 text-xs">Approve step {{ $needStep }} (overwrite)</button>
                                    </form>
                                @endif
                            @endforeach
                        </div>
                    @endif
                </div>
            @empty
                <p class="text-sm text-gray-500">No plans yet.</p>
            @endforelse
        </section>
    </div>
</x-layouts.app>
