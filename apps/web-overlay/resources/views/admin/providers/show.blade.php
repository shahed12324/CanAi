<x-layouts.admin :title="$provider->name" :crumbs="['AI Provider' => route('admin.providers.index'), $provider->name => null]">
    {{-- JARVIS ONE: page-specific visual identity; existing actions preserved --}}
    <header class="jarvis-module-hero jarvis-accent-violet" data-jarvis-module="admin" aria-label="Admin details">
      <div class="jarvis-module-hero__identity"><span class="jarvis-module-hero__glyph" aria-hidden="true">✦</span><div>
        <div class="jarvis-module-hero__eyebrow">JARVIS ONE <span aria-hidden="true">/</span> ADMIN</div>
        <h1>Admin details</h1><p>Manage your platform, permissions and operations.</p>
      </div></div>
      <span class="jarvis-module-hero__section">Admin Workspace</span>
    </header>
    @if (session('test_failed'))<p class="rounded bg-red-50 p-3 text-sm text-red-700">{{ session('test_failed') }}</p>@endif
    <div class="flex flex-wrap items-center justify-between gap-3">
        <h1 class="text-xl font-semibold">{{ $provider->name }} <span class="text-sm font-normal text-gray-500">{{ $provider->adapter }} · {{ $provider->status }}</span></h1>
        <form method="POST" action="{{ route('admin.providers.test', $provider->id) }}">@csrf<x-button>Test connection</x-button></form>
    </div>

    <section class="max-w-xl space-y-3 rounded-lg bg-white p-5 shadow">
        <h2 class="font-medium">API key</h2>
        @if ($credential)
            <p class="text-sm">Active key: <strong>{{ $credential->label }}</strong> (vault v{{ $credential->key_version }}) · {{ \Illuminate\Support\Carbon::parse($credential->last_rotated_at ?? $credential->created_at)->format('Y-m-d') }} — <span class="text-gray-500">The value can no longer be seen.</span></p>
        @else
            <p class="text-sm text-yellow-700">No key yet.</p>
        @endif
        <form method="POST" action="{{ route('admin.providers.credential.save', $provider->id) }}" class="space-y-3" autocomplete="off">
            @csrf
            <x-field name="label" label="Label" value="main" />
            <div><label for="api_key" class="block text-sm font-medium text-gray-700">{{ $credential ? 'New key (the old one will be revoked)' : 'API key' }}</label>
                <input id="api_key" name="api_key" type="password" autocomplete="new-password" required minlength="8" class="w-full rounded-md border border-gray-300 px-3 py-2">
                @error('api_key')<p class="text-sm text-red-600">{{ $message }}</p>@enderror</div>
            <x-button>{{ $credential ? 'Key Change' : 'Key Save' }}</x-button>
        </form>
        @if ($credential)
            <form method="POST" action="{{ route('admin.providers.credential.revoke', $provider->id) }}" onsubmit="return confirm('Key Revoking will disable the provider. Sure?')">@csrf @method('DELETE')<x-button variant="danger">Key Cancel</x-button></form>
        @endif
        @if (count($history))<p class="text-xs text-gray-500">Revoked: @foreach ($history as $h){{ $h->label }} ({{ \Illuminate\Support\Carbon::parse($h->created_at)->format('Y-m-d') }})@if (! $loop->last), @endif @endforeach</p>@endif
    </section>

    <section class="max-w-xl space-y-3 rounded-lg bg-white p-5 shadow">
        <h2 class="font-medium">Settings</h2>
        <form method="POST" action="{{ route('admin.providers.update', $provider->id) }}" class="space-y-3">
            @csrf @method('PUT')
            <x-field name="name" label="Name" :value="$provider->name" required />
            <div class="grid gap-3 sm:grid-cols-3">
                <div><label for="adapter" class="block text-sm font-medium text-gray-700">Adapter</label>
                    <select id="adapter" name="adapter" class="w-full rounded-md border border-gray-300 px-3 py-2">@foreach ($adapters as $a)<option value="{{ $a }}" @selected($provider->adapter === $a)>{{ $a }}</option>@endforeach</select></div>
                <div><label for="type" class="block text-sm font-medium text-gray-700">Type</label>
                    <select id="type" name="type" class="w-full rounded-md border border-gray-300 px-3 py-2">@foreach (['llm', 'voice', 'image', 'video', 'embedding'] as $t)<option value="{{ $t }}" @selected($provider->type === $t)>{{ $t }}</option>@endforeach</select></div>
                <div><label for="status" class="block text-sm font-medium text-gray-700">Status</label>
                    <select id="status" name="status" class="w-full rounded-md border border-gray-300 px-3 py-2">@foreach (['disabled', 'active', 'degraded'] as $t)<option value="{{ $t }}" @selected($provider->status === $t)>{{ $t }}</option>@endforeach</select></div>
            </div>
            <x-field name="base_url" label="Base URL" type="url" :value="$provider->base_url" />
            <div class="grid gap-3 sm:grid-cols-2">
                <x-field name="timeout_ms" type="number" label="Timeout (ms)" :value="$provider->timeout_ms" required />
                <x-field name="priority" type="number" label="Priority" :value="$provider->priority" required />
            </div>
            <div><label for="fallback_provider_id" class="block text-sm font-medium text-gray-700">Fallback on failure</label>
                <select id="fallback_provider_id" name="fallback_provider_id" class="w-full rounded-md border border-gray-300 px-3 py-2">
                    <option value="">— None —</option>@foreach ($others as $o)<option value="{{ $o->id }}" @selected($provider->fallback_provider_id === $o->id)>{{ $o->name }}</option>@endforeach
                </select>
                @error('fallback_provider_id')<p class="text-sm text-red-600">{{ $message }}</p>@enderror</div>
            <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="allow_private" value="1" @checked($provider->allow_private)> Allow local/private address</label>
            @if ($credential)<label class="flex items-center gap-2 text-sm"><input type="checkbox" name="confirm_credential_reuse" value="1"> Adapter/URL If changed, the saved key will go to the new address — I confirm</label>@endif
            <x-button>Save</x-button>
        </form>
    </section>

    <section class="rounded-lg bg-white p-5 shadow">
        <h2 class="mb-2 font-medium">Recent health checks</h2>
        @forelse ($checks as $c)
            <p class="flex justify-between border-t py-1 text-sm"><span>{{ $c->status }}@if ($c->error_code) · {{ $c->error_code }}@endif @if ($c->latency_ms !== null) · {{ $c->latency_ms }} ms @endif</span><span class="text-gray-500">{{ \Illuminate\Support\Carbon::parse($c->checked_at)->diffForHumans() }}</span></p>
        @empty<p class="text-sm text-gray-500">No checks yet.</p>@endforelse
    </section>

    <h2 class="pt-2 text-lg font-semibold">Model</h2>
    @foreach ($models as $m)
        @php($caps = $capabilities[$m->id] ?? [])
        <section class="space-y-3 rounded-lg bg-white p-5 shadow">
            <h3 class="font-medium">{{ $m->display_name }} <span class="text-xs font-normal text-gray-500">{{ $m->model_key }} · {{ $m->status }}</span></h3>
            <p class="text-xs text-gray-600">Current price:
                @if ($m->credit_multiplier !== null) in {{ $m->input_per_1m ?? '—' }} / out {{ $m->output_per_1m ?? '—' }} (per 1M, {{ $m->currency }}) · req {{ $m->per_request ?? '—' }} · multiplier ×{{ $m->credit_multiplier }}
                @else <span class="text-yellow-700">None — cannot be enabled unless free-tier</span>@endif</p>

            <form method="POST" action="{{ route('admin.models.update', $m->id) }}" class="grid gap-3 sm:grid-cols-3">
                @csrf @method('PUT')
                <x-field name="display_name" label="Name" :value="$m->display_name" required />
                <div><label class="block text-sm font-medium text-gray-700">Category</label>
                    <select name="category" class="w-full rounded-md border border-gray-300 px-3 py-2">@foreach ($categories as $t)<option value="{{ $t }}" @selected($m->category === $t)>{{ $t }}</option>@endforeach</select></div>
                <div><label class="block text-sm font-medium text-gray-700">Status</label>
                    <select name="status" class="w-full rounded-md border border-gray-300 px-3 py-2">@foreach (['hidden', 'active', 'deprecated'] as $t)<option value="{{ $t }}" @selected($m->status === $t)>{{ $t }}</option>@endforeach</select></div>
                <x-field name="context_window" type="number" label="Context window" :value="$m->context_window" />
                <x-field name="max_output" type="number" label="Max output" :value="$m->max_output" />
                <x-field name="quality_rank" type="number" min="1" max="100" label="Value (1–100)" :value="$m->quality_rank" required />
                <div class="sm:col-span-3 flex flex-wrap gap-x-4 gap-y-1 text-sm">
                    @foreach ($capabilityList as $c)<label class="flex items-center gap-1"><input type="checkbox" name="capabilities[]" value="{{ $c }}" @checked(in_array($c, $caps, true))> {{ $c }}</label>@endforeach
                    <label class="flex items-center gap-1"><input type="checkbox" name="is_free_tier" value="1" @checked($m->is_free_tier)> Free tier</label>
                    <label class="flex items-center gap-1"><input type="checkbox" name="is_local" value="1" @checked($m->is_local)> Local</label>
                </div>
                <div class="sm:col-span-3"><x-button>Save model</x-button></div>
            </form>

            <details><summary class="cursor-pointer text-sm text-indigo-600">New price (history stays intact)</summary>
                <form method="POST" action="{{ route('admin.models.pricing', $m->id) }}" class="mt-2 grid gap-3 sm:grid-cols-4">
                    @csrf
                    @foreach (['input_per_1m' => 'Input/1M', 'output_per_1m' => 'Output/1M', 'per_request' => 'Per request', 'per_image' => 'Per image', 'per_second' => 'Per second'] as $f => $l)
                        <div><label class="block text-xs text-gray-600">{{ $l }}</label><input name="{{ $f }}" type="number" step="any" min="0" class="w-full rounded border border-gray-300 px-2 py-1"></div>
                    @endforeach
                    <div><label class="block text-xs text-gray-600">Currency</label><input name="currency" value="{{ $m->currency ?? 'USD' }}" maxlength="3" class="w-full rounded border border-gray-300 px-2 py-1" required></div>
                    <div><label class="block text-xs text-gray-600">Credit multiplier</label><input name="credit_multiplier" type="number" step="any" min="0.0001" value="{{ $m->credit_multiplier ?? 1 }}" class="w-full rounded border border-gray-300 px-2 py-1" required></div>
                    <div><label class="block text-xs text-gray-600">Effective (optional)</label><input name="effective_from" type="datetime-local" class="w-full rounded border border-gray-300 px-2 py-1"></div>
                    <div class="sm:col-span-4"><x-button variant="ghost">Add price</x-button></div>
                </form>
            </details>

            <details><summary class="cursor-pointer text-sm text-indigo-600">Access by plan</summary>
                <form method="POST" action="{{ route('admin.models.access', $m->id) }}" class="mt-2 space-y-1 text-sm">
                    @csrf @method('PUT')
                    @php($mine = ($rules[$m->id] ?? collect())->pluck('allowed', 'plan_id'))
                    @foreach ($plans as $pl)
                        @php($cur = $mine->has($pl->id) ? ($mine[$pl->id] ? 'allow' : 'deny') : 'default')
                        <div class="flex items-center gap-3"><span class="w-32">{{ $pl->name }}</span>
                            <select name="access[{{ $pl->id }}]" class="rounded border border-gray-300 px-2 py-1" aria-label="{{ $pl->name }}">
                                <option value="default" @selected($cur === 'default')>Default (yes if free-tier, otherwise no)</option>
                                <option value="allow" @selected($cur === 'allow')>Allowed</option>
                                <option value="deny" @selected($cur === 'deny')>Denied</option>
                            </select></div>
                    @endforeach
                    <x-button variant="ghost">Save access</x-button>
                </form>
            </details>
        </section>
    @endforeach

    <section class="max-w-md space-y-3 rounded-lg bg-white p-5 shadow">
        <h2 class="font-medium">New model</h2>
        <form method="POST" action="{{ route('admin.models.store', $provider->id) }}" class="space-y-3">
            @csrf
            <x-field name="model_key" label="Model key (as the provider knows it)" required />
            <x-field name="display_name" label="Display name" required />
            <div><label for="category_new" class="block text-sm font-medium text-gray-700">Category</label>
                <select id="category_new" name="category" class="w-full rounded-md border border-gray-300 px-3 py-2">@foreach ($categories as $t)<option value="{{ $t }}">{{ $t }}</option>@endforeach</select></div>
            <x-button>Add</x-button>
        </form>
    </section>
</x-layouts.admin>
