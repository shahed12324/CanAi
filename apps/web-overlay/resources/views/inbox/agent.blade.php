<x-layouts.app title="AI Support agent">
    {{-- JARVIS ONE: page-specific visual identity; existing actions preserved --}}
    <header class="jarvis-module-hero jarvis-accent-violet" data-jarvis-module="inbox" aria-label="AI Support agent">
      <div class="jarvis-module-hero__identity"><span class="jarvis-module-hero__glyph" aria-hidden="true">✦</span><div>
        <div class="jarvis-module-hero__eyebrow">JARVIS ONE <span aria-hidden="true">/</span> INBOX</div>
        <h1>AI Support agent</h1>
      </div></div>
      <span class="jarvis-module-hero__section">Inbox Workspace</span>
    </header>

    <div class="flex items-center justify-between"><h2 class="text-xl font-semibold">AI Support agent</h2><a class="text-sm text-indigo-600" href="{{ route('inbox.index') }}">← Inbox</a></div>
    @if (session('status'))<p class="rounded bg-green-50 p-3 text-sm text-green-700">{{ session('status') }}</p>@endif
    <p class="rounded bg-gray-50 p-3 text-sm text-gray-700">The agent only your <b> published products</b> and the below <b> business information</b> answers from. Taking orders, complaints, "I want a human", photos/files — these go straight to your team. Every reply costs your workspace’s AI credits. When starting out use <b> draft</b> mode first.</p>
    <form method="POST" action="{{ route('inbox.agent.save') }}" class="space-y-4 rounded-lg bg-white p-5 text-sm shadow">@csrf
        <h3 class="font-semibold">Mode per channel</h3>
        @forelse ($channels as $ch)
            <label class="flex flex-wrap items-center justify-between gap-2"><span>{{ $ch['name'] }} <span class="text-xs text-gray-500">({{ \App\Inbox\InboxText::kindBn($ch['kind']) }})</span></span>
                <select name="modes[{{ $ch['id'] }}]" class="rounded border px-2 py-1" @disabled(! $canManage)>
                    @foreach (['off' => 'Closed', 'draft' => 'Draft (staff sends)', 'auto' => 'Auto (AI sends itself)'] as $v => $l)<option value="{{ $v }}" @selected($ch['agent_mode'] === $v)>{{ $l }}</option>@endforeach
                </select></label>
        @empty<p class="text-gray-500">First add a channel.</p>@endforelse
        <label class="block">Business information (delivery, address, opening hours, return policy, contact — AI will say only what is written here)
            <textarea name="business_info" rows="7" maxlength="3000" class="mt-1 block w-full rounded border px-2 py-1" @disabled(! $canManage)>{{ old('business_info', $settings['business_info']) }}</textarea></label>
        <label class="block">Speaking style (optional, for example "polite and concise")
            <textarea name="instructions" rows="3" maxlength="1000" class="mt-1 block w-full rounded border px-2 py-1" @disabled(! $canManage)>{{ old('instructions', $settings['instructions']) }}</textarea></label>
        @if ($canManage)<button class="rounded bg-indigo-600 px-4 py-2 text-white">Save</button>@else<p class="text-gray-500">Changes are not permitted.</p>@endif
    </form>
</x-layouts.app>
