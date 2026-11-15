<x-layouts.app :title="$j['title']">
    {{-- JARVIS ONE: page-specific visual identity; existing actions preserved --}}
    <header class="jarvis-module-hero jarvis-accent-violet" data-jarvis-module="video" aria-label="Video details">
      <div class="jarvis-module-hero__identity"><span class="jarvis-module-hero__glyph" aria-hidden="true">✦</span><div>
        <div class="jarvis-module-hero__eyebrow">JARVIS ONE <span aria-hidden="true">/</span> VIDEO</div>
        <h1>Video details</h1><p>Create and manage video projects.</p>
      </div></div>
      <span class="jarvis-module-hero__section">Video Workspace</span>
    </header>

    <p class="text-sm"><a class="text-indigo-600 hover:underline" href="{{ route('video.index') }}">← Video studio</a></p>
    <h2 class="mt-1 text-xl font-semibold">{{ $j['title'] }}</h2>
    @if (session('status'))<p class="mt-2 rounded bg-green-50 p-3 text-sm text-green-700">{{ session('status') }}</p>@endif
    @if (in_array($j['status'], ['queued', 'rendering'], true))<meta http-equiv="refresh" content="8">@endif

    <section class="mt-3 rounded-lg bg-white p-4 text-sm shadow">
        <p>Status: <b>{{ $status[$j['status']] ?? $j['status'] }}</b> · {{ $j['seconds'] }} seconds · {{ count($j['scenes']) }} scenes · {{ $j['size_key'] }}</p>
        @if ($j['status'] === 'queued')<p class="mt-2 text-gray-600">In the queue — one is made per minute. The page refreshes by itself.</p>
            <form method="POST" action="{{ route('video.cancel', $j['id']) }}" class="mt-2">@csrf<button class="rounded border px-3 py-1 text-red-700">Cancel (credits refunded)</button></form>@endif
        @if ($j['status'] === 'rendering')<p class="mt-2 text-gray-600">Being made… can take 1–5 minutes.</p>@endif
        @if ($j['status'] === 'failed')<p class="mt-2 rounded bg-red-50 p-3 text-red-700">{{ $j['error'] }}</p>@endif
        @if ($j['status'] === 'done')
            <video controls preload="metadata" class="mt-2 max-h-[70vh] w-full max-w-md rounded bg-black" src="{{ route('video.file', $j['id']) }}"></video>
            <p class="mt-2"><a class="rounded bg-indigo-600 px-3 py-1 text-white" href="{{ route('video.file', $j['id']) }}?dl=1">Download (MP4, {{ number_format(($j['bytes'] ?? 0) / 1048576, 1) }} MB)</a>
            <span class="ml-2 text-xs text-gray-500">Credits deducted: {{ $j['charged'] }}</span></p>@endif
        <ol class="mt-3 list-decimal pl-5 text-gray-700">@foreach ($j['scenes'] as $s)<li>{{ $s['headline'] ?: ($s['subline'] ?: $s['cta']) }} <span class="text-xs text-gray-500">({{ $s['seconds'] }}s)</span></li>@endforeach</ol>
        @if (in_array($j['status'], ['done', 'failed', 'cancelled'], true))
            <form method="POST" action="{{ route('video.destroy', $j['id']) }}" class="mt-3" onsubmit="return confirm('Delete the video?')">@csrf<button class="rounded border px-3 py-1 text-red-700">Delete</button></form>@endif
    </section>
</x-layouts.app>
