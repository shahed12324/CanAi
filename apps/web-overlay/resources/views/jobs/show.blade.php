<x-layouts.app :title="$job->title">
    {{-- JARVIS ONE: page-specific visual identity; existing actions preserved --}}
    <header class="jarvis-module-hero jarvis-accent-violet" data-jarvis-module="jobs" aria-label="Jobs details">
      <div class="jarvis-module-hero__identity"><span class="jarvis-module-hero__glyph" aria-hidden="true">✦</span><div>
        <div class="jarvis-module-hero__eyebrow">JARVIS ONE <span aria-hidden="true">/</span> JOBS</div>
        <h1>Jobs details</h1>
      </div></div>
      <span class="jarvis-module-hero__section">Jobs Workspace</span>
    </header>
    <p class="text-sm"><a class="text-indigo-600 underline" href="{{ route('jobs.index') }}">← Scheduled jobs</a></p>
    <h2 class="mt-1 text-xl font-semibold">{{ $job->title }}</h2>
    <p class="text-sm text-gray-600">{{ $job->when }} · {{ $job->tz }} · {{ $job->status }}</p>
    @if (session('status'))<p class="mt-2 rounded bg-green-50 p-3 text-sm text-green-700">{{ session('status') }}</p>@endif
    <p class="mt-2 max-w-3xl whitespace-pre-line rounded bg-white p-3 text-sm shadow">{{ $job->prompt }}</p>
    <form method="POST" action="{{ route('jobs.run', $job->id) }}" class="mt-3" onsubmit="this.querySelector('button').disabled=true;this.querySelector('button').textContent='Running… (up to a minute)'">@csrf <button class="rounded bg-indigo-600 px-3 py-1 text-sm text-white">Run now (uses credits)</button></form>

    <h3 class="mt-5 font-semibold">Recent runs</h3>
    @forelse ($runs as $r)
        <div class="mt-2 max-w-3xl rounded-lg bg-white p-3 text-sm shadow">
            <p class="text-xs text-gray-500">{{ \Illuminate\Support\Carbon::parse($r->started_at)->timezone($job->tz)->format('D d M Y, H:i') }} · {{ $r->status }} @if ($r->credits) · {{ $r->credits }} credits @endif · sent to: {{ $r->delivered ?: '—' }}</p>
            <p class="mt-1 whitespace-pre-line">{{ $r->status === 'ok' ? $r->output : $r->error }}</p>
        </div>
    @empty
        <p class="mt-2 text-sm text-gray-500">It has not run yet.</p>
    @endforelse
</x-layouts.app>
