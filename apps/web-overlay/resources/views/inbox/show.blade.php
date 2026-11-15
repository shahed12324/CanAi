<x-layouts.app title="Conversation">
    {{-- JARVIS ONE: page-specific visual identity; existing actions preserved --}}
    <header class="jarvis-module-hero jarvis-accent-violet" data-jarvis-module="inbox" aria-label="Conversation">
      <div class="jarvis-module-hero__identity"><span class="jarvis-module-hero__glyph" aria-hidden="true">✦</span><div>
        <div class="jarvis-module-hero__eyebrow">JARVIS ONE <span aria-hidden="true">/</span> INBOX</div>
        <h1>Conversation</h1>
      </div></div>
      <span class="jarvis-module-hero__section">Inbox Workspace</span>
    </header>

    <div class="flex flex-wrap items-center justify-between gap-2">
        <h2 class="text-xl font-semibold">{{ $c['display_name'] ?: 'Guest '.substr($c['external_id'], -4) }} <span class="ml-1 rounded bg-gray-100 px-2 py-0.5 text-xs font-normal">{{ $kindBn }}</span></h2>
        <a class="text-sm text-indigo-600" href="{{ route('inbox.index') }}">← Inbox</a>
    </div>
    @if (session('status'))<p class="rounded bg-green-50 p-3 text-sm text-green-700">{{ session('status') }}</p>@endif
    @if ($c['mode'] === 'human')<p class="rounded bg-amber-50 p-3 text-sm text-amber-800">The chat is with a human — AI will not reply.@if ($c['handoff_reason']) Reason: {{ \App\Inbox\Agent\Triggers::reasonBn($c['handoff_reason']) }}@endif</p>@endif
    @if (! $windowOpen)<p class="rounded bg-red-50 p-3 text-sm text-red-700">24-hour rule: 24 hours have passed since the customer’s last message — a reply cannot be sent on this channel now. It reopens when the customer writes again.</p>@endif
    <section id="thread" class="space-y-2 rounded-lg bg-white p-5 shadow" data-feed="{{ route('inbox.messages', $c['id']) }}" data-last="{{ $messages ? end($messages)['seq'] : 0 }}">
        @foreach ($messages as $m)
            <div class="flex {{ $m['direction'] === 'out' ? 'justify-end' : '' }}">
                <div class="max-w-[80%] whitespace-pre-wrap rounded-lg px-3 py-2 text-sm {{ $m['direction'] === 'out' ? ($m['sender'] === 'agent' ? 'bg-purple-100' : 'bg-indigo-100') : 'bg-gray-100' }}">
                    {{ $m['body'] }}
                    <span class="mt-1 block text-[11px] text-gray-500">{{ ['customer' => 'Customer', 'staff' => 'Employee', 'agent' => 'AI', 'system' => 'System'][$m['sender']] }} · {{ \Illuminate\Support\Carbon::parse($m['created_at'])->format('d M H:i') }}@if ($m['status'] === 'queued') · Pending @elseif ($m['status'] === 'failed') · Failed @endif</span>
                </div>
            </div>
        @endforeach
    </section>
    @if ($canReply)
    <section class="space-y-3 rounded-lg bg-white p-5 text-sm shadow">
        @if (! empty($draft))
            <div class="rounded bg-purple-50 p-3 text-purple-900">AI-draft (review before sending, edit if needed):
                <form method="POST" action="{{ route('inbox.draft.dismiss', $c['id']) }}" class="mt-2">@csrf<button class="rounded border px-2 py-0.5 text-xs">Discard draft</button></form></div>
        @endif
        <form method="POST" action="{{ route('inbox.reply', $c['id']) }}" class="space-y-2">@csrf
            <textarea name="body" rows="3" maxlength="4000" required class="block w-full rounded border px-2 py-1" @disabled(! $windowOpen)>{{ old('body', $draft['body'] ?? '') }}</textarea>
            @error('body')<p class="text-red-600">{{ $message }}</p>@enderror
            <button class="rounded bg-indigo-600 px-3 py-1 text-white" @disabled(! $windowOpen)>Send</button>
        </form>
        <div class="flex flex-wrap gap-2 border-t pt-3">
            @if ($c['mode'] === 'bot')
                <form method="POST" action="{{ route('inbox.mode', $c['id']) }}" class="flex gap-2">@csrf<input type="hidden" name="mode" value="human"><input name="reason" placeholder="Reason (optional)" maxlength="200" class="rounded border px-2 py-1"><button class="rounded bg-amber-600 px-3 py-1 text-white">I’ll take over (AI stays quiet)</button></form>
            @else
                <form method="POST" action="{{ route('inbox.mode', $c['id']) }}">@csrf<input type="hidden" name="mode" value="bot"><button class="rounded border px-3 py-1">AI-Hand back to</button></form>
            @endif
            <form method="POST" action="{{ route('inbox.resolve', $c['id']) }}">@csrf<input type="hidden" name="resolved" value="{{ $c['status'] === 'open' ? 1 : 0 }}"><button class="rounded border px-3 py-1">{{ $c['status'] === 'open' ? 'Resolved' : 'Reopen' }}</button></form>
        </div>
    </section>
    @endif
    <script>(function(){var t=document.getElementById('thread');if(!t)return;var last=parseInt(t.dataset.last||'0',10);setInterval(function(){if(document.hidden)return;
      fetch(t.dataset.feed+'?after='+last,{headers:{'Accept':'application/json'},credentials:'same-origin'}).then(function(r){return r.ok?r.json():null}).then(function(j){
        if(j&&j.messages&&j.messages.length){location.reload();}}).catch(function(){});},8000);})();</script>
</x-layouts.app>
