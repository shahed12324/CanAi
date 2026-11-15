<x-layouts.app title="Comment inbox">
    {{-- JARVIS ONE: page-specific visual identity; existing actions preserved --}}
    <header class="jarvis-module-hero jarvis-accent-violet" data-jarvis-module="social" aria-label="Comment inbox">
      <div class="jarvis-module-hero__identity"><span class="jarvis-module-hero__glyph" aria-hidden="true">✦</span><div>
        <div class="jarvis-module-hero__eyebrow">JARVIS ONE <span aria-hidden="true">/</span> SOCIAL</div>
        <h1>Comment inbox</h1><p>Publish content, monitor channels and manage social activity.</p>
      </div></div>
      <span class="jarvis-module-hero__section">Social Workspace</span>
    </header>

    <p class="text-sm"><a class="text-indigo-600 hover:underline" href="{{ route('social.index') }}">← Social planner</a></p>
    <h2 class="mt-1 text-xl font-semibold">Comment inbox</h2>
    <p class="mt-1 rounded bg-amber-50 p-3 text-sm text-amber-800">Only comments on Facebook posts auto-posted from the system arrive (refreshed about every 10 minutes; the page token needs <b>pages_read_engagement</b> and for replying <b>pages_manage_engagement</b> permission). The AI or you write the reply <b> draft</b> text; it goes to Facebook (in about 1 minute) only when someone with approval power approves that exact text. Nothing goes without approval. A sent reply cannot be deleted — you must delete it on Facebook yourself.</p>
    @if (session('error'))<p class="mt-2 rounded bg-red-50 p-3 text-sm text-red-700">{{ session('error') }}</p>@endif
    @if (session('status'))<p class="mt-2 rounded bg-green-50 p-3 text-sm text-green-700">{{ session('status') }}</p>@endif
    @if ($errors->any())<p class="mt-2 rounded bg-red-50 p-3 text-sm text-red-700">{{ $errors->first() }}</p>@endif
    <div class="mt-3 flex flex-wrap gap-2 text-sm">@foreach (['todo' => 'To do', 'replied' => 'Reply sent', 'ignored' => 'Dismissed', 'all' => 'All'] as $k => $l)<a class="rounded border px-3 py-1 {{ $k === $f ? 'bg-indigo-600 text-white' : '' }}" href="{{ route('social.comments', ['f' => $k]) }}">{{ $l }}</a>@endforeach</div>

    @forelse ($rows as $c)
    <article class="mt-3 rounded-lg bg-white p-4 text-sm shadow">
        <div class="flex flex-wrap items-center justify-between gap-2"><span class="text-xs text-gray-500">Post: <a class="text-indigo-600 hover:underline" href="{{ route('social.show', $c['post_id']) }}">{{ $c['post_title'] }}</a> · {{ \Illuminate\Support\Carbon::parse($c['commented_at'])->setTimezone($tz)->format('m-d H:i') }}</span>
            <span class="rounded bg-gray-100 px-2 text-xs">{{ $statusLabels[$c['status']] ?? $c['status'] }}</span></div>
        <p class="mt-1"><b>{{ $c['author'] !== '' ? $c['author'] : 'Someone' }}:</b> {{ $c['message'] }}</p>
        @if ($c['reply_error'])<p class="mt-1 rounded bg-red-50 p-2 text-xs text-red-700">{{ $c['reply_error'] }}</p>@endif

        @if (in_array($c['status'], ['new', 'drafted'], true) && $can_manage)
        <form method="POST" action="{{ route('social.comments.draft', $c['id']) }}" class="mt-2 grid gap-1">@csrf
            <label class="grid gap-1"> reply draft<textarea name="reply" rows="3" maxlength="1000" required class="rounded border px-2 py-1">{{ $c['reply'] }}</textarea></label>
            <div><button class="rounded border px-3 py-1">Save draft</button></div></form>
        <form method="POST" action="{{ route('social.comments.ai', $c['id']) }}" class="mt-2 flex flex-wrap items-end gap-2" onsubmit="var b=this.querySelector('button'); b.disabled = true; b.textContent = 'AI writing…'">@csrf
            <input type="hidden" name="rid" value="{{ \App\Actions\Tenancy\CreateWorkspace::newId() }}">
            <label class="grid flex-1 gap-1 text-xs">Business information (optional — true prices/delivery; otherwise the AI will not state prices)<input name="notes" maxlength="500" class="rounded border px-2 py-1 text-sm"></label>
            <button class="rounded bg-indigo-50 px-3 py-1 text-indigo-700">AI draft</button></form>
        @endif

        @if ($c['status'] === 'drafted' && $c['reply'])
        <div class="mt-2 rounded border border-indigo-200 bg-indigo-50 p-2"><p class="text-xs text-gray-600">Draft saved for approval — exactly this text will go:</p><p class="mt-1 whitespace-pre-line">{{ $c['reply'] }}</p>
            @if ($can_approve)<form method="POST" action="{{ route('social.comments.approve', $c['id']) }}" class="mt-2">@csrf<input type="hidden" name="reply" value="{{ $c['reply'] }}"><button class="rounded bg-green-600 px-3 py-1 text-white">Approve — goes to Facebook</button></form>@endif</div>
        @elseif (in_array($c['status'], ['approved', 'replying', 'replied'], true) && $c['reply'])
        <div class="mt-2 rounded border border-green-200 bg-green-50 p-2"><p class="text-xs text-gray-600">Replies @if ($c['approver']) (Approval: {{ $c['approver'] }})@endif:</p><p class="mt-1 whitespace-pre-line">{{ $c['reply'] }}</p></div>
        @endif

        <div class="mt-2 flex flex-wrap gap-2">
            @if ($c['status'] === 'approved' && $can_manage)<form method="POST" action="{{ route('social.comments.withdraw', $c['id']) }}">@csrf<button class="rounded border px-3 py-1">Withdraw approval</button></form>@endif
            @if ($c['status'] === 'failed' && $can_approve)<form method="POST" action="{{ route('social.comments.retry', $c['id']) }}" onsubmit="return confirm('Facebook Did the reply not reach the page? Check first — otherwise it may go twice.')">@csrf<button class="rounded border px-3 py-1">Facebook-I checked at, retry</button></form>@endif
            @if (in_array($c['status'], ['new', 'drafted', 'approved', 'failed'], true) && $can_manage)<form method="POST" action="{{ route('social.comments.ignore', $c['id']) }}">@csrf<button class="rounded border px-3 py-1 text-gray-600">Skip</button></form>@endif
        </div>
    </article>
    @empty<p class="mt-3 rounded bg-white p-4 text-sm text-gray-500 shadow">No comments here.</p>@endforelse
</x-layouts.app>
