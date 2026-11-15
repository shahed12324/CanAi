<x-layouts.app title="Inbox">
    {{-- JARVIS ONE: page-specific visual identity; existing actions preserved --}}
    <header class="jarvis-module-hero jarvis-accent-violet" data-jarvis-module="inbox" aria-label="Inbox">
      <div class="jarvis-module-hero__identity"><span class="jarvis-module-hero__glyph" aria-hidden="true">✦</span><div>
        <div class="jarvis-module-hero__eyebrow">JARVIS ONE <span aria-hidden="true">/</span> INBOX</div>
        <h1>Inbox</h1>
      </div></div>
      <span class="jarvis-module-hero__section">Inbox Workspace</span>
    </header>

    <meta http-equiv="refresh" content="30">
    <div class="flex flex-wrap items-center justify-between gap-2"><h2 class="text-xl font-semibold">Inbox</h2>
        <a class="text-sm text-indigo-600" href="{{ route('inbox.agent') }}">AI Agent</a> · <a class="text-sm text-indigo-600" href="{{ route('inbox.channels') }}">Channels and widget</a></div>
    @php($label = ['open' => 'Open', 'unread' => 'Unread', 'human' => 'With a human', 'mine' => 'Mine', 'resolved' => 'Resolved'])
    <nav class="flex flex-wrap gap-2 text-sm">
        @foreach ($filters as $f)<a href="{{ route('inbox.index', ['f' => $f]) }}" class="rounded px-3 py-1 {{ $filter === $f ? 'bg-gray-800 text-white' : 'bg-white text-gray-700 shadow' }}">{{ $label[$f] }}</a>@endforeach
    </nav>
    <section class="rounded-lg bg-white p-5 shadow">
        @forelse ($rows as $r)
            <a href="{{ route('inbox.show', $r['id']) }}" class="flex flex-wrap items-center justify-between gap-2 border-t py-2 text-sm first:border-0 hover:bg-gray-50">
                <span><strong>{{ $r['display_name'] ?: 'Guest '.substr($r['external_id'], -4) }}</strong>
                    <span class="ml-1 rounded bg-gray-100 px-1 text-xs">{{ \App\Inbox\InboxText::kindBn($r['kind']) }}</span>
                    @if ($r['unread'] > 0)<span class="ml-1 rounded-full bg-red-600 px-2 text-xs text-white">{{ $r['unread'] }}</span>@endif
                    @if ($r['mode'] === 'human')<span class="ml-1 rounded bg-amber-50 px-1 text-xs text-amber-700">Human</span>@endif
                    <span class="block text-gray-600">{{ $r['preview'] }}</span></span>
                <span class="text-xs text-gray-500">{{ \Illuminate\Support\Carbon::parse($r['last_message_at'])->diffForHumans() }}</span>
            </a>
        @empty<p class="text-sm text-gray-500">No conversations. <a class="text-indigo-600" href="{{ route('inbox.channels') }}">Put the chat widget on your website</a>.</p>@endforelse
    </section>
</x-layouts.app>
