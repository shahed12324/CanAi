<x-layouts.admin title="Free trials" :crumbs="['Free trials' => null]">
    {{-- JARVIS ONE: page-specific visual identity; existing actions preserved --}}
    <header class="jarvis-module-hero jarvis-accent-violet" data-jarvis-module="admin" aria-label="Free trials">
      <div class="jarvis-module-hero__identity"><span class="jarvis-module-hero__glyph" aria-hidden="true">✦</span><div>
        <div class="jarvis-module-hero__eyebrow">JARVIS ONE <span aria-hidden="true">/</span> ADMIN</div>
        <h1>Free trials</h1><p>Manage your platform, permissions and operations.</p>
      </div></div>
      <span class="jarvis-module-hero__section">Admin Workspace</span>
    </header>
    @if (session('status'))<p class="rounded bg-green-50 p-3 text-sm text-green-700">{{ session('status') }}</p>@endif
    @if ($errors->any())<p class="rounded bg-red-50 p-3 text-sm text-red-700">{{ $errors->first() }}</p>@endif
    <h1 class="text-xl font-semibold">Free trials</h1>

    <section class="max-w-2xl space-y-3 rounded-lg bg-white p-5 shadow">
        <h2 class="font-medium">Start a trial for a shop</h2>
        <form method="POST" action="{{ route('admin.trials.grant') }}" class="grid gap-3 sm:grid-cols-4">
            @csrf
            <input name="tenant" required placeholder="Shop slug or id" class="rounded border border-gray-300 px-3 py-2 text-sm sm:col-span-2">
            <select name="plan_id" class="rounded border-gray-300 text-sm">@foreach ($plans as $p)<option value="{{ $p->id }}">{{ $p->name }}{{ $p->trial_days ? ' (default '.$p->trial_days.' d)' : '' }}</option>@endforeach</select>
            <input name="days" type="number" min="1" max="365" value="14" class="rounded border border-gray-300 px-3 py-2 text-sm">
            <x-button>Start trial</x-button>
        </form>
        <p class="text-xs text-gray-500">When the trial ends the shop automatically goes back to the free plan (same scheduler as paid periods).</p>
    </section>

    <section class="rounded-lg bg-white p-5 shadow">
        <h2 class="mb-2 font-medium">Running trials ({{ $trials->count() }})</h2>
        @forelse ($trials as $s)
            <div class="flex flex-wrap items-center justify-between gap-3 border-t py-2 text-sm">
                <span><a class="text-indigo-600" href="{{ route('admin.tenants.show', $s->tenant_id) }}">{{ $s->shop }}</a> · {{ $s->plan }} · ends {{ $s->current_period_end ? \Illuminate\Support\Carbon::parse($s->current_period_end)->format('d M Y') : '—' }}</span>
                <span class="flex gap-2">
                    <form method="POST" action="{{ route('admin.trials.extend', $s->id) }}" class="flex gap-1">@csrf <input name="days" type="number" min="1" max="365" value="7" class="w-16 rounded border border-gray-300 px-2 py-1 text-sm"> <x-button variant="ghost">Extend</x-button></form>
                    <form method="POST" action="{{ route('admin.trials.end', $s->id) }}" onsubmit="return confirm('End this trial now?')">@csrf <x-button variant="ghost">End now</x-button></form>
                </span>
            </div>
        @empty<p class="text-sm text-gray-500">No running trials.</p>@endforelse
    </section>
</x-layouts.admin>
