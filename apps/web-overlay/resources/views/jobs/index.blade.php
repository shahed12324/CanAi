<x-layouts.app title="Scheduled jobs">
    {{-- JARVIS ONE: page-specific visual identity; existing actions preserved --}}
    <header class="jarvis-module-hero jarvis-accent-violet" data-jarvis-module="jobs" aria-label="Scheduled jobs">
      <div class="jarvis-module-hero__identity"><span class="jarvis-module-hero__glyph" aria-hidden="true">✦</span><div>
        <div class="jarvis-module-hero__eyebrow">JARVIS ONE <span aria-hidden="true">/</span> JOBS</div>
        <h1>Scheduled jobs</h1>
      </div></div>
      <span class="jarvis-module-hero__section">Jobs Workspace</span>
    </header>

    <h2 class="text-xl font-semibold">Scheduled jobs</h2>
    <p class="mt-1 text-sm text-gray-600">Tell the AI what to do and when, in Bangla or English. It runs on time, even when you are away, and sends the answer to your inbox and your Telegram/WhatsApp. Each run uses AI credits like a normal chat message.</p>
    @if (session('status'))<p class="mt-2 rounded bg-green-50 p-3 text-sm text-green-700">{{ session('status') }}</p>@endif
    @if ($errors->any())<p class="mt-2 rounded bg-red-50 p-3 text-sm text-red-700">{{ $errors->first() }}</p>@endif

    <form method="POST" action="{{ route('jobs.store') }}" class="mt-3 grid max-w-2xl gap-3 rounded-lg bg-white p-4 text-sm shadow">
        @csrf
        <label class="grid gap-1"><span class="font-medium">What should the AI do?</span>
            <textarea name="prompt" rows="3" maxlength="1500" required placeholder="Summarise yesterday's orders and list customers who still owe money" class="rounded border px-2 py-1">{{ old('prompt') }}</textarea></label>
        <label class="grid gap-1"><span class="font-medium">When?</span>
            <input name="when" maxlength="200" value="{{ old('when') }}" placeholder="প্রতিদিন সকাল ৯টায়" class="rounded border px-2 py-1">
            <span class="text-xs text-gray-500">Examples: {{ $examples }}. Repeats at least every 15 minutes.</span></label>
        <label class="grid gap-1">Name (optional)<input name="title" maxlength="120" value="{{ old('title') }}" class="rounded border px-2 py-1"></label>
        <div class="grid gap-3 sm:grid-cols-2">
            <label class="grid gap-1">Send the answer to
                <select name="deliver" class="rounded border px-2 py-1">
                    <option value="both" @selected(old('deliver', 'both') === 'both')>App inbox + Telegram/WhatsApp</option>
                    <option value="inbox" @selected(old('deliver') === 'inbox')>App inbox only</option>
                    <option value="channel" @selected(old('deliver') === 'channel')>Telegram/WhatsApp only</option>
                </select></label>
            <label class="grid gap-1">Time zone<input name="tz" value="{{ $tz }}" class="rounded border px-2 py-1"></label>
        </div>
        <label class="flex items-center gap-2"><input type="checkbox" name="continuity" value="1" @checked(old('continuity'))> Every run continues the same chat (it remembers earlier runs; costs more over time)</label>
        <div><button type="submit" class="rounded bg-indigo-600 px-4 py-2 text-white">Schedule</button></div>
    </form>

    <section class="mt-5 max-w-4xl">
        <h3 class="font-semibold">Your jobs ({{ count($jobs) }}/{{ $max }})</h3>
        @forelse ($jobs as $j)
            <div class="mt-2 rounded-lg bg-white p-3 text-sm shadow">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <a href="{{ route('jobs.show', $j->id) }}" class="font-medium text-indigo-700 underline">{{ $j->title }}</a>
                    <span class="rounded px-2 py-0.5 text-xs {{ $j->status === 'active' ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-600' }}">{{ $j->status }}</span>
                </div>
                <p class="mt-1 text-xs text-gray-600">{{ $j->when }} · {{ $j->tz }} · {{ $j->run_count }} runs @if ($j->next_run_at) · next: {{ \Illuminate\Support\Carbon::parse($j->next_run_at)->timezone($j->tz)->format('D d M, H:i') }} @endif @if ($j->last_status) · last: {{ $j->last_status }} @endif</p>
                <p class="mt-1 text-xs text-gray-500">{{ \Illuminate\Support\Str::limit($j->prompt, 160) }}</p>
                <div class="mt-2 flex gap-2 text-xs">
                    @if ($j->status !== 'done')<form method="POST" action="{{ route('jobs.toggle', $j->id) }}">@csrf <button class="rounded border px-2 py-1">{{ $j->status === 'active' ? 'Pause' : 'Resume' }}</button></form>@endif
                    <form method="POST" action="{{ route('jobs.destroy', $j->id) }}" onsubmit="return confirm('Delete this job?')">@csrf @method('DELETE') <button class="rounded border border-red-300 px-2 py-1 text-red-700">Delete</button></form>
                </div>
            </div>
        @empty
            <p class="mt-2 text-sm text-gray-500">No jobs yet.</p>
        @endforelse
    </section>
</x-layouts.app>
