<x-layouts.admin title="Chat analytics" :crumbs="['Chat' => null]">
    {{-- JARVIS ONE: page-specific visual identity; existing actions preserved --}}
    <header class="jarvis-module-hero jarvis-accent-violet" data-jarvis-module="admin" aria-label="Chat analytics">
      <div class="jarvis-module-hero__identity"><span class="jarvis-module-hero__glyph" aria-hidden="true">✦</span><div>
        <div class="jarvis-module-hero__eyebrow">JARVIS ONE <span aria-hidden="true">/</span> ADMIN</div>
        <h1>Chat analytics</h1><p>Manage your platform, permissions and operations.</p>
      </div></div>
      <span class="jarvis-module-hero__section">Admin Workspace</span>
    </header>
    @php($fmt = fn ($v) => \App\Credits\CreditMath::format($v))
    <h1 class="text-xl font-semibold">Chat analytics</h1>
    <div class="flex gap-2 text-sm">
        @foreach ($periods as $p)<a href="{{ route('admin.chat.index', ['days' => $p]) }}" class="rounded px-2 py-1 {{ $p === $days ? 'bg-indigo-600 text-white' : 'bg-white text-gray-700 shadow' }}">{{ $p }} days</a>@endforeach
    </div>
    <div class="grid gap-4 sm:grid-cols-5">
        <div class="rounded-lg bg-white p-4 shadow"><p class="text-xs text-gray-500">Replies</p><p class="text-2xl font-semibold">{{ number_format($totals->answers) }}</p></div>
        <div class="rounded-lg bg-white p-4 shadow"><p class="text-xs text-gray-500">Failed/partial</p><p class="text-2xl font-semibold">{{ number_format($totals->failed) }}</p></div>
        <div class="rounded-lg bg-white p-4 shadow"><p class="text-xs text-gray-500">Credit cost</p><p class="text-2xl font-semibold tabular-nums">{{ $fmt($totals->credits) }}</p></div>
        <div class="rounded-lg bg-white p-4 shadow"><p class="text-xs text-gray-500">Tokens (in/out)</p><p class="text-lg font-semibold tabular-nums">{{ number_format($totals->input_tokens) }} / {{ number_format($totals->output_tokens) }}</p></div>
        <div class="rounded-lg bg-white p-4 shadow"><p class="text-xs text-gray-500">Active workspaces</p><p class="text-2xl font-semibold">{{ number_format($totals->tenants) }}</p></div>
    </div>

    <section class="rounded-lg bg-white p-5 shadow">
        <h2 class="mb-2 font-medium">By model</h2>
        <table class="min-w-full text-sm"><thead class="text-left text-gray-600"><tr><th class="py-1">Model</th><th>Replies</th><th>Failed</th><th>Failure rate</th><th>Credits</th></tr></thead><tbody>
            @forelse ($models as $m)<tr class="border-t"><td class="py-1">{{ $m->name }}</td><td>{{ number_format($m->answers) }}</td><td>{{ number_format($m->failed) }}</td><td>{{ $m->answers > 0 ? round(100 * $m->failed / $m->answers, 1) : 0 }}%</td><td class="tabular-nums">{{ $fmt($m->credits) }}</td></tr>
            @empty<tr><td colspan="5" class="py-3 text-gray-500">No data.</td></tr>@endforelse
        </tbody></table>
    </section>

    <section class="rounded-lg bg-white p-5 shadow">
        <h2 class="mb-2 font-medium">Provider Failures</h2>
        @forelse ($failures as $f)<div class="flex flex-wrap justify-between border-t py-1 text-sm"><span>{{ $f->provider }} · <code>{{ $f->code }}</code></span><span>{{ number_format($f->n) }} times · last {{ \Illuminate\Support\Carbon::parse($f->last_at)->diffForHumans() }}</span></div>
        @empty<p class="text-sm text-green-700">No failures.</p>@endforelse
    </section>

    <section class="rounded-lg bg-white p-5 shadow">
        <h2 class="mb-2 font-medium">By day</h2>
        <table class="min-w-full text-sm"><thead class="text-left text-gray-600"><tr><th class="py-1"> days</th><th>Replies</th><th>Failed</th><th>Credits</th></tr></thead><tbody>
            @forelse ($daily as $d)<tr class="border-t"><td class="py-1">{{ $d->day }}</td><td>{{ number_format($d->answers) }}</td><td>{{ number_format($d->failed) }}</td><td class="tabular-nums">{{ $fmt($d->credits) }}</td></tr>
            @empty<tr><td colspan="4" class="py-3 text-gray-500">No data.</td></tr>@endforelse
        </tbody></table>
    </section>
</x-layouts.admin>
