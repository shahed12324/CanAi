<x-layouts.admin title="AI assistant" :crumbs="['AI assistant' => null]">
    {{-- JARVIS ONE: page-specific visual identity; existing actions preserved --}}
    <header class="jarvis-module-hero jarvis-accent-violet" data-jarvis-module="admin" aria-label="AI assistant">
      <div class="jarvis-module-hero__identity"><span class="jarvis-module-hero__glyph" aria-hidden="true">✦</span><div>
        <div class="jarvis-module-hero__eyebrow">JARVIS ONE <span aria-hidden="true">/</span> ADMIN</div>
        <h1>AI assistant</h1><p>Manage your platform, permissions and operations.</p>
      </div></div>
      <span class="jarvis-module-hero__section">Admin Workspace</span>
    </header>
    <h1 class="text-xl font-semibold">AI assistant</h1>
    <p class="text-sm text-gray-600">Ask about shops, revenue, tickets, trials and usage, or give a command. Changes always wait for your confirmation. Type <code>help</code> for examples.</p>

    <section class="max-w-3xl space-y-3">
        @forelse ($log as $e)
            <div class="rounded-lg bg-indigo-50 p-3 text-sm"><span class="text-xs text-gray-500">{{ $e['at'] }} · You</span><p>{{ $e['q'] }}</p></div>
            <div class="rounded-lg bg-white p-3 text-sm shadow">
                @foreach ($e['a'] as $line)<p class="whitespace-pre-line">{{ $line }}</p>@endforeach
            </div>
        @empty
            <p class="text-sm text-gray-500">No messages yet. Try “how many shops”.</p>
        @endforelse
    </section>

    @if ($pending)
        <section class="max-w-3xl rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm">
            <p class="mb-2 font-medium">Confirm: {{ $pending['summary'] }}</p>
            <div class="flex gap-2">
                <form method="POST" action="{{ route('admin.assistant.confirm', $pending['id']) }}">@csrf <x-button>Confirm</x-button></form>
                <form method="POST" action="{{ route('admin.assistant.cancel') }}">@csrf <x-button variant="ghost">Cancel</x-button></form>
            </div>
        </section>
    @endif

    <form method="POST" action="{{ route('admin.assistant.ask') }}" class="flex max-w-3xl gap-2">
        @csrf
        <input name="text" autofocus autocomplete="off" maxlength="300" required placeholder="Ask or command…" class="flex-1 rounded border border-gray-300 px-3 py-2 text-sm">
        <x-button>Send</x-button>
    </form>
</x-layouts.admin>
