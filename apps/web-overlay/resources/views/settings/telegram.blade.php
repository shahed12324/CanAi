<x-layouts.app title="Telegram">
    {{-- JARVIS ONE: page-specific visual identity; existing actions preserved --}}
    <header class="jarvis-module-hero jarvis-accent-violet" data-jarvis-module="settings" aria-label="Telegram">
      <div class="jarvis-module-hero__identity"><span class="jarvis-module-hero__glyph" aria-hidden="true">✦</span><div>
        <div class="jarvis-module-hero__eyebrow">JARVIS ONE <span aria-hidden="true">/</span> SETTINGS</div>
        <h1>Telegram</h1>
      </div></div>
      <span class="jarvis-module-hero__section">Settings Workspace</span>
    </header>

    <h2 class="text-xl font-semibold">Telegram Connections</h2>
    @if (session('status'))<p class="rounded bg-green-50 p-3 text-sm text-green-700">{{ session('status') }}</p>@endif
    @if ($errors->any())<div class="rounded bg-red-50 p-3 text-sm text-red-700">@foreach ($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div>@endif
    <section class="max-w-xl space-y-3 rounded-lg bg-white p-5 shadow">
        @if ($link)
            <p class="text-sm">Linked @if ($link->external_username) — <strong>&#64;{{ $link->external_username }}</strong>@endif · {{ \Illuminate\Support\Carbon::parse($link->verified_at)->diffForHumans() }}</p>
            <p class="text-sm text-gray-600">Telegram Credits for questions asked from will be deducted from this workspace’s wallet. In the bot <code>/new</code> writing starts a new chat.</p>
            <form method="POST" action="{{ route('telegram.unlink') }}" onsubmit="return confirm('Telegram Unlink?')">
                @csrf @method('DELETE')
                <x-button>Unlink</x-button>
            </form>
        @elseif (! $bot)
            <p class="text-sm text-gray-600">Telegram The bot has not been enabled yet. Tell the platform admin.</p>
        @else
            @if ($code)
                <div class="space-y-2 rounded border border-indigo-200 bg-indigo-50 p-4">
                    <p class="text-sm">Your code (10 minutes, works only once):</p>
                    <p class="select-all text-2xl font-mono font-semibold tracking-widest">{{ $code }}</p>
                    <p class="text-sm">Telegram-At @if ($bot->bot_username)<a class="text-indigo-600" href="https://t.me/{{ $bot->bot_username }}" target="_blank" rel="noopener">&#64;{{ $bot->bot_username }}</a>@else the bot @endif open and write:<br><code>/link {{ $code }}</code></p>
                </div>
            @else
                <p class="text-sm text-gray-600">If you link your own Telegram you can ask the bot questions directly. Get a code with the button below, then send it to the bot.</p>
            @endif
            <form method="POST" action="{{ route('telegram.code') }}">@csrf <x-button>{{ $code ? 'Get a new code' : 'Get code' }}</x-button></form>
        @endif
    </section>
</x-layouts.app>
