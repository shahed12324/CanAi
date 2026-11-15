<x-layouts.app title="Social insights">
    {{-- JARVIS ONE: page-specific visual identity; existing actions preserved --}}
    <header class="jarvis-module-hero jarvis-accent-violet" data-jarvis-module="social" aria-label="Social insights">
      <div class="jarvis-module-hero__identity"><span class="jarvis-module-hero__glyph" aria-hidden="true">✦</span><div>
        <div class="jarvis-module-hero__eyebrow">JARVIS ONE <span aria-hidden="true">/</span> SOCIAL</div>
        <h1>Social insights</h1><p>Publish content, monitor channels and manage social activity.</p>
      </div></div>
      <span class="jarvis-module-hero__section">Social Workspace</span>
    </header>

    <p class="text-sm"><a class="text-indigo-600 hover:underline" href="{{ route('social.index') }}">← Social planner</a></p>
    <h2 class="mt-1 text-xl font-semibold">Post insights</h2>
    <p class="mt-1 rounded bg-amber-50 p-3 text-sm text-amber-800">Numbers come only for posts auto-posted from the system to a Facebook page (not if you post yourself and "Posted" mark it). Refreshed every 6 hours for the first 14 days starting 10 minutes after posting, then daily, up to 60 days. Facebook does not always give reach/views — then "—" stays.</p>
    <div class="mt-3 flex gap-2 text-sm">@foreach ([7, 30, 90] as $d)<a class="rounded border px-3 py-1 {{ $d === $days ? 'bg-indigo-600 text-white' : '' }}" href="{{ route('social.insights', ['days' => $d]) }}">{{ $d }} days</a>@endforeach</div>

    <section class="mt-3 grid grid-cols-2 gap-2 text-sm sm:grid-cols-5">
        <div class="rounded-lg bg-white p-3 shadow">Post<br><b class="text-lg">{{ $totals['posts'] }}</b></div><div class="rounded-lg bg-white p-3 shadow">👍 Reactions<br><b class="text-lg">{{ $totals['reactions'] }}</b></div>
        <div class="rounded-lg bg-white p-3 shadow">💬 Comments<br><b class="text-lg">{{ $totals['comments'] }}</b></div><div class="rounded-lg bg-white p-3 shadow">↪ Shares<br><b class="text-lg">{{ $totals['shares'] }}</b></div>
        <div class="rounded-lg bg-white p-3 shadow">👁 Reach/views<br><b class="text-lg">{{ $totals['reach'] }}</b></div></section>

    @if (count($top) > 0)<section class="mt-3 rounded-lg bg-white p-4 text-sm shadow"><h3 class="font-semibold">Posts with the most engagement</h3>
        <ol class="mt-1 list-decimal pl-5">@foreach ($top as $r)<li><a class="text-indigo-600 hover:underline" href="{{ route('social.show', $r['id']) }}">{{ $r['title'] }}</a> — {{ (int) $r['reactions'] + (int) $r['comments'] + (int) $r['shares'] }} (👍{{ $r['reactions'] }} 💬{{ $r['comments'] }} ↪{{ $r['shares'] ?? 0 }})</li>@endforeach</ol></section>@endif

    <section class="mt-3 overflow-x-auto rounded-lg bg-white p-4 text-sm shadow"><table class="w-full min-w-[640px] text-left"><thead><tr class="text-xs text-gray-500"><th>Post</th><th>Platform</th><th>When</th><th>👍</th><th>💬</th><th>↪</th><th>👁</th></tr></thead>
        <tbody>@forelse ($rows as $r)<tr class="border-t"><td class="py-1"><a class="text-indigo-600 hover:underline" href="{{ route('social.show', $r['id']) }}">{{ $r['title'] }}</a></td><td>{{ $platforms[$r['platform']] ?? $r['platform'] }}</td>
            <td>{{ \Illuminate\Support\Carbon::parse($r['posted_at'])->setTimezone($tz)->format('m-d H:i') }}</td><td>{{ $r['reactions'] ?? '—' }}</td><td>{{ $r['comments'] ?? '—' }}</td><td>{{ $r['shares'] ?? '—' }}</td><td>{{ $r['reach'] ?? '—' }}</td></tr>
        @empty<tr><td colspan="7" class="py-2 text-gray-500">No posts in this period.</td></tr>@endforelse</tbody></table></section>
</x-layouts.app>
