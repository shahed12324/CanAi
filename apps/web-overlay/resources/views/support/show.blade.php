<x-layouts.app :title="$ticket->subject">
    {{-- JARVIS ONE: page-specific visual identity; existing actions preserved --}}
    <header class="jarvis-module-hero jarvis-accent-violet" data-jarvis-module="support" aria-label="Support details">
      <div class="jarvis-module-hero__identity"><span class="jarvis-module-hero__glyph" aria-hidden="true">✦</span><div>
        <div class="jarvis-module-hero__eyebrow">JARVIS ONE <span aria-hidden="true">/</span> SUPPORT</div>
        <h1>Support details</h1><p>Review tickets and help requests.</p>
      </div></div>
      <span class="jarvis-module-hero__section">Support Workspace</span>
    </header>
    <a href="{{ route('support.index') }}" class="text-sm text-indigo-600">← All tickets</a>
    <h2 class="text-xl font-semibold">{{ $ticket->subject }}</h2>
    <p class="text-sm text-gray-500">{{ ucfirst($ticket->category) }} · {{ ucfirst($ticket->priority) }} priority · Status: <strong>{{ ucfirst($ticket->status) }}</strong></p>
    @if ($errors->any())<p class="rounded bg-red-50 p-3 text-sm text-red-700">{{ $errors->first() }}</p>@endif

    <section class="max-w-2xl space-y-3">
        @foreach ($messages as $m)
            <div class="rounded-lg p-3 text-sm shadow {{ $m->author_kind === 'shop' ? 'ml-10 bg-indigo-50' : 'mr-10 bg-white' }}">
                <p class="mb-1 text-xs text-gray-500">{{ $m->author_kind === 'shop' ? ($m->name ?? 'You') : ($m->author_kind === 'ai' ? 'CANAI assistant' : 'CANAI support') }} · {{ \Illuminate\Support\Carbon::parse($m->created_at)->diffForHumans() }}</p>
                <p class="whitespace-pre-line">{{ $m->body }}</p>
            </div>
        @endforeach
    </section>

    @if ($ticket->status !== 'closed')
        <section class="max-w-2xl space-y-3 rounded-lg bg-white p-5 shadow">
            <form method="POST" action="{{ route('support.reply', $ticket->id) }}" class="space-y-3">
                @csrf
                <textarea name="body" rows="3" maxlength="5000" required placeholder="Write a reply…" class="w-full rounded border-gray-300 text-sm"></textarea>
                <x-button>Reply</x-button>
            </form>
            <form method="POST" action="{{ route('support.close', $ticket->id) }}">@csrf <x-button>Close ticket</x-button></form>
        </section>
    @endif
</x-layouts.app>
