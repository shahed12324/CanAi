<x-layouts.app title="Social planner">
    {{-- JARVIS ONE: page-specific visual identity; existing actions preserved --}}
    <header class="jarvis-module-hero jarvis-accent-violet" data-jarvis-module="social" aria-label="Social planner">
      <div class="jarvis-module-hero__identity"><span class="jarvis-module-hero__glyph" aria-hidden="true">✦</span><div>
        <div class="jarvis-module-hero__eyebrow">JARVIS ONE <span aria-hidden="true">/</span> SOCIAL</div>
        <h1>Social planner</h1><p>Publish content, monitor channels and manage social activity.</p>
      </div></div>
      <span class="jarvis-module-hero__section">Social Workspace</span>
    </header>

    <div class="flex flex-wrap items-center justify-between gap-2">
        <h2 class="text-xl font-semibold">Social content planner</h2>
        <div class="flex items-center gap-2">@if ($can_manage)<a class="rounded border bg-indigo-50 px-3 py-1 text-sm" href="{{ route('social.plan') }}">AI Plans</a>@endif<a class="rounded border px-3 py-1 text-sm" href="{{ route('social.insights') }}">Insights</a><a class="rounded border px-3 py-1 text-sm" href="{{ route('social.growth') }}">Growth</a><a class="rounded border px-3 py-1 text-sm" href="{{ route('social.comments') }}">Comments</a><a class="rounded border px-3 py-1 text-sm" href="{{ route('social.accounts') }}">Facebook Page</a>
        @if ($can_manage)<a class="rounded bg-indigo-600 px-3 py-1 text-sm text-white" href="{{ route('social.create') }}">+ New post</a>@endif</div>
    </div>
    <p class="mt-1 rounded bg-amber-50 p-3 text-sm text-amber-800">Here post planning, captions, picking images/videos and approval take place. <b>Facebook If you connect a page, approved posts go out by themselves on time; on other platforms (or without a page) download the image/video, post it yourself and "Posted" mark it.</b> Times shown {{ $tz->getName() }} according to.</p>
    @if (session('status'))<p class="mt-2 rounded bg-green-50 p-3 text-sm text-green-700">{{ session('status') }}</p>@endif

    <div class="mt-3 flex items-center gap-3 text-sm">
        <a class="rounded border px-2 py-1" href="{{ route('social.index', ['m' => $prev]) }}">← Previous month</a>
        <b>{{ sprintf('%04d-%02d', $year, $month) }}</b>
        <a class="rounded border px-2 py-1" href="{{ route('social.index', ['m' => $next]) }}">Next month →</a>
    </div>
    <div class="mt-2 overflow-x-auto"><table class="w-full min-w-[720px] table-fixed border-collapse text-xs">
        <thead><tr>@foreach (['Sat', 'Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri'] as $d)<th class="border bg-gray-50 p-1">{{ $d }}</th>@endforeach</tr></thead>
        <tbody>@foreach ($grid as $week)<tr>@foreach ($week as $c)
            <td class="h-24 align-top border p-1 {{ $c['in_month'] ? 'bg-white' : 'bg-gray-50 text-gray-400' }} {{ $c['today'] ? 'ring-2 ring-indigo-400' : '' }}">
                <div class="flex justify-between"><span>{{ $c['day'] }}</span>@if ($can_manage && $c['in_month'])<a class="text-indigo-600" href="{{ route('social.create', ['d' => $c['date']]) }}" title="Posts on this day">+</a>@endif</div>
                @foreach ($c['posts'] as $p)
                    <a href="{{ route('social.show', $p['id']) }}" class="mt-1 block truncate rounded px-1 py-0.5 {{ $p['status'] === 'posted' ? 'bg-green-100' : ($p['status'] === 'approved' ? 'bg-blue-100' : ($p['status'] === 'pending' ? 'bg-amber-100' : 'bg-gray-100')) }}" title="{{ $p['title'] }} — {{ $status[$p['status']] ?? $p['status'] }}">{{ $platforms[$p['platform']] ?? $p['platform'] }}: {{ $p['title'] }}</a>
                @endforeach
            </td>@endforeach</tr>@endforeach</tbody></table></div>
    <p class="mt-1 text-xs text-gray-500">Colors: grey = draft, yellow = awaiting approval, blue = approved, green = posted.</p>

    <section class="mt-4 rounded-lg bg-white p-4 text-sm shadow"><h3 class="font-semibold">Posts with no time set</h3>
        @forelse ($unscheduled as $p)
            <p class="mt-1"><a class="text-indigo-600 hover:underline" href="{{ route('social.show', $p['id']) }}">{{ $p['title'] }}</a> <span class="text-xs text-gray-500">· {{ $platforms[$p['platform']] ?? $p['platform'] }} · {{ $status[$p['status']] ?? $p['status'] }} · {{ $p['author'] }}</span></p>
        @empty<p class="mt-1 text-gray-500">None.</p>@endforelse
    </section>
</x-layouts.app>
