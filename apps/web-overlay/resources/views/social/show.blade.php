<x-layouts.app :title="$p['title']">
    {{-- JARVIS ONE: page-specific visual identity; existing actions preserved --}}
    <header class="jarvis-module-hero jarvis-accent-violet" data-jarvis-module="social" aria-label="Social details">
      <div class="jarvis-module-hero__identity"><span class="jarvis-module-hero__glyph" aria-hidden="true">✦</span><div>
        <div class="jarvis-module-hero__eyebrow">JARVIS ONE <span aria-hidden="true">/</span> SOCIAL</div>
        <h1>Social details</h1><p>Publish content, monitor channels and manage social activity.</p>
      </div></div>
      <span class="jarvis-module-hero__section">Social Workspace</span>
    </header>

    <p class="text-sm"><a class="text-indigo-600 hover:underline" href="{{ route('social.index') }}">← Social planner</a></p>
    <h2 class="mt-1 text-xl font-semibold">{{ $p['title'] }}</h2>
    @if (session('status'))<p class="mt-2 rounded bg-green-50 p-3 text-sm text-green-700">{{ session('status') }}</p>@endif
    @if (session('error'))<p class="mt-2 rounded bg-red-50 p-3 text-sm text-red-700">{{ session('error') }}</p>@endif
    <section class="mt-3 rounded-lg bg-white p-4 text-sm shadow">
        <p>Status: <b>{{ $status[$p['status']] ?? $p['status'] }}</b> · {{ $platforms[$p['platform']] ?? $p['platform'] }} · Author: {{ $p['author'] ?? '—' }}
            · Time: {{ $local !== '' ? str_replace('T', ' ', $local).' ('.$tz.')' : 'Not set' }}</p>
        @if ($p['account_name'])<p class="mt-1 text-gray-600">Auto-post: {{ $p['platform'] === 'youtube' ? 'YouTube Channels' : 'Facebook Page' }} <b>{{ $p['account_name'] }}</b>@if ($p['status'] === 'approved' && ! $p['publish_state']) — Will go by itself when due @endif</p>@endif
        @if ($p['publish_state'] === 'publishing')<p class="mt-1 rounded bg-blue-50 p-2 text-blue-800">Now {{ $p['platform'] === 'youtube' ? 'YouTube-is uploading to (large videos can take a few minutes)' : 'Facebook-is going to' }}… The page will refresh by itself.</p><meta http-equiv="refresh" content="10">@endif
        @if ($p['publish_state'] === 'failed')<p class="mt-1 rounded bg-red-50 p-2 text-red-700">Not auto-posted: {{ $p['publish_error'] }}</p>@endif
        @if ($p['platform'] === 'youtube')<p class="mt-1 text-gray-600">YouTube: <b>{{ \App\Social\YoutubeMeta::PRIVACY[$p['yt_privacy']] ?? $p['yt_privacy'] }}</b> · <a class="text-indigo-600 underline" href="{{ route('social.youtube', $p['id']) }}">YouTube Settings (tags, thumbnail, channel, AI SEO)</a></p>
            @if ($p['publish_note'])<p class="mt-1 rounded bg-amber-50 p-2 text-amber-900">{{ $p['publish_note'] }}</p>@endif @endif
        @if ($p['reject_note'])<p class="mt-2 rounded bg-red-50 p-2 text-red-700">Reason for return: {{ $p['reject_note'] }}</p>@endif
        @if ($p['status'] === 'approved' || $p['status'] === 'posted')<p class="mt-1 text-gray-600">Approval: {{ $p['approver'] ?? '—' }}</p>@endif
        @if ($p['status'] === 'posted')<p class="mt-1 text-green-700">Posted {{ $p['post_url'] ? ': ' : '' }}@if ($p['post_url'])<a class="underline" rel="noopener noreferrer" target="_blank" href="{{ $p['post_url'] }}">{{ $p['post_url'] }}</a>@endif</p>@endif
        @if ($stats)<div class="mt-3 grid grid-cols-2 gap-2 rounded border bg-gray-50 p-3 sm:grid-cols-4"><div>👍 Reactions<br><b>{{ $stats['reactions'] }}</b></div><div>💬 Comments<br><b>{{ $stats['comments'] }}</b></div>
            <div>↪ Shares<br><b>{{ $stats['shares'] ?? '—' }}</b></div><div>👁 Reach/views<br><b>{{ $stats['reach'] ?? '—' }}</b></div>
            <p class="col-span-full text-xs text-gray-500">Facebook Taken from: {{ \Illuminate\Support\Carbon::parse($stats['taken_at'])->setTimezone($tz)->format('Y-m-d H:i') }} ({{ $tz }}) — Refreshed regularly. Facebook does not always give reach/views.</p></div>
        @elseif ($p['status'] === 'posted' && $p['account_name'])<p class="mt-2 text-xs text-gray-500">Numbers have not arrived yet — fetching starts 10 minutes after the post.</p>@endif
        @if ($p['note'])<p class="mt-2 rounded bg-indigo-50 p-2 text-indigo-900">🖼 Media idea: {{ $p['note'] }}</p>@endif
        <pre class="mt-3 whitespace-pre-wrap rounded bg-gray-50 p-3 font-sans">{{ $p['caption'] }}</pre>
        <p class="mt-1 text-xs {{ mb_strlen($p['caption']) > $limit ? 'text-red-700' : 'text-gray-500' }}">{{ mb_strlen($p['caption']) }} / {{ $limit }} characters</p>
        @if ($p['media_kind'] === 'design')<img class="mt-3 max-h-96 rounded border" alt="" src="{{ route('social.media', $p['id']) }}">
            <p class="mt-1"><a class="text-indigo-600 underline" href="{{ route('social.media', $p['id']) }}?dl=1">Download image</a></p>@endif
        @if ($p['media_kind'] === 'video')<video controls preload="metadata" class="mt-3 max-h-96 w-full max-w-md rounded bg-black" src="{{ route('social.media', $p['id']) }}"></video>
            <p class="mt-1"><a class="text-indigo-600 underline" href="{{ route('social.media', $p['id']) }}?dl=1">Download video</a></p>@endif
    </section>

    <section class="mt-3 grid gap-2 rounded-lg bg-white p-4 text-sm shadow">
        @if ($mine && $can_manage)
            @if ($p['status'] === 'draft')
                <div class="flex flex-wrap gap-2"><a class="rounded border px-3 py-1" href="{{ route('social.edit', $p['id']) }}">Change</a>
                    <form method="POST" action="{{ route('social.submit', $p['id']) }}">@csrf<button class="rounded bg-indigo-600 px-3 py-1 text-white">Send for approval</button></form></div>@endif
            @if (in_array($p['status'], ['pending', 'approved'], true) && $p['publish_state'] !== 'publishing')
                <form method="POST" action="{{ route('social.reopen', $p['id']) }}">@csrf<button class="rounded border px-3 py-1">Return to draft</button></form>@endif
            @if ($p['status'] === 'approved' && $p['publish_state'] === 'failed')
                <form method="POST" action="{{ route('social.retry', $p['id']) }}">@csrf<button class="rounded bg-indigo-600 px-3 py-1 text-white">Try again (check the Facebook page first)</button></form>@endif
            @if ($p['status'] === 'approved' && $p['publish_state'] !== 'publishing')
                <form method="POST" action="{{ route('social.posted', $p['id']) }}" class="flex flex-wrap items-center gap-2">@csrf
                    <input name="url" maxlength="300" placeholder="Post link (https://…, optional)" class="min-w-64 rounded border px-2 py-1"><button class="rounded bg-green-600 px-3 py-1 text-white">Posted</button></form>@endif
        @endif
        @if ($can_approve && $p['status'] === 'pending')
            <form method="POST" action="{{ route('social.decide', $p['id']) }}" class="grid gap-2 rounded border bg-amber-50 p-3">@csrf
                <input name="note" maxlength="300" placeholder="If returning, write a reason (optional)" class="rounded border px-2 py-1">
                <div class="flex gap-2"><button name="decision" value="approve" class="rounded bg-green-600 px-3 py-1 text-white">Approve</button><button name="decision" value="reject" class="rounded border bg-white px-3 py-1 text-red-700">Return</button></div></form>@endif
        @if ($mine && $can_manage && in_array($p['status'], ['draft', 'pending', 'approved'], true) && $p['publish_state'] !== 'publishing')
            <form method="POST" action="{{ route('social.cancel', $p['id']) }}" onsubmit="return confirm('Cancel the post?')">@csrf<button class="rounded border px-3 py-1 text-red-700">Cancel</button></form>@endif
        @if ($mine && $can_manage && in_array($p['status'], ['draft', 'cancelled', 'posted'], true))
            <form method="POST" action="{{ route('social.destroy', $p['id']) }}" onsubmit="return confirm('Delete?')">@csrf<button class="rounded border px-3 py-1 text-red-700">Delete</button></form>@endif
    </section>
</x-layouts.app>
