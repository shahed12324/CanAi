<x-layouts.app title="AI Model">
    {{-- JARVIS ONE: page-specific visual identity; existing actions preserved --}}
    <header class="jarvis-module-hero jarvis-accent-violet" data-jarvis-module="models" aria-label="AI Model">
      <div class="jarvis-module-hero__identity"><span class="jarvis-module-hero__glyph" aria-hidden="true">✦</span><div>
        <div class="jarvis-module-hero__eyebrow">JARVIS ONE <span aria-hidden="true">/</span> MODELS</div>
        <h1>AI Model</h1>
      </div></div>
      <span class="jarvis-module-hero__section">Models Workspace</span>
    </header>

    <h1 class="text-xl font-semibold">AI Model</h1>
    <p class="text-sm text-gray-600">The models available on your plan. The cost column is a credit multiplier (×1 = standard), not the provider’s real price.</p>

    @if (session('status'))<p class="rounded bg-green-50 p-3 text-sm text-green-700">{{ session('status') }}</p>@endif
    @if ($errors->any())<div class="rounded bg-red-50 p-3 text-sm text-red-700">@foreach ($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div>@endif

    <div class="overflow-x-auto rounded-lg bg-white shadow">
        <table class="min-w-full text-sm">
            <thead class="bg-gray-50 text-left text-gray-600"><tr>
                <th class="px-4 py-2">Model</th><th class="px-4 py-2">Capability</th><th class="px-4 py-2">Context</th><th class="px-4 py-2">Value</th><th class="px-4 py-2">Expenses</th><th class="px-4 py-2">Avg response</th>
            </tr></thead>
            <tbody>
            @forelse ($models as $m)
                <tr class="border-t">
                    <td class="px-4 py-2"><strong>{{ $m['name'] }}</strong> @if ($prefs['default_model_id'] === $m['id'])<span class="rounded bg-indigo-50 px-1 text-xs text-indigo-700">Default</span>@endif
                        <div class="text-xs text-gray-500">{{ $m['provider'] }}@if ($m['is_free_tier']) · Free tier @endif @if ($m['is_local']) · Local @endif</div></td>
                    <td class="px-4 py-2">{{ implode(', ', $m['capabilities']) }}</td>
                    <td class="px-4 py-2">{{ $m['context_window'] ? number_format($m['context_window']) : '—' }}</td>
                    <td class="px-4 py-2">{{ $m['quality_rank'] }}/100</td>
                    <td class="px-4 py-2">{{ $m['credit_multiplier'] !== null ? '×'.rtrim(rtrim(number_format($m['credit_multiplier'], 4), '0'), '.') : '—' }}</td>
                    <td class="px-4 py-2">{{ $m['latency_ms'] !== null ? (int) $m['latency_ms'].' ms' : '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="6" class="px-4 py-6 text-center text-gray-500">No model is enabled on this plan yet.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    @if ($canManage && count($models))
        <section class="max-w-md space-y-3 rounded-lg bg-white p-5 shadow">
            <h2 class="font-medium">Your preference</h2>
            <form method="POST" action="{{ route('models.preferences') }}" class="space-y-3">
                @csrf @method('PUT')
                <div><label for="routing_mode" class="block text-sm font-medium text-gray-700">Model selection method</label>
                    <select id="routing_mode" name="routing_mode" class="w-full rounded-md border border-gray-300 px-3 py-2">
                        @foreach (['balanced' => 'Balanced (the platform decides)', 'cheapest' => 'Cheapest', 'fastest' => 'Fastest', 'best_quality' => 'Best quality', 'manual' => 'Choose yourself (default model)'] as $v => $l)
                            <option value="{{ $v }}" @selected($prefs['routing_mode'] === $v)>{{ $l }}</option>
                        @endforeach
                    </select></div>
                <div><label for="default_model_id" class="block text-sm font-medium text-gray-700">Default model</label>
                    <select id="default_model_id" name="default_model_id" class="w-full rounded-md border border-gray-300 px-3 py-2">
                        <option value="">— None —</option>
                        @foreach ($models as $m)<option value="{{ $m['id'] }}" @selected($prefs['default_model_id'] === $m['id'])>{{ $m['name'] }}</option>@endforeach
                    </select></div>
                <x-button>Save</x-button>
            </form>
        </section>
    @endif
</x-layouts.app>
