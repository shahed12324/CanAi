<x-layouts.app title="WhatsApp">
    {{-- JARVIS ONE: page-specific visual identity; existing actions preserved --}}
    <header class="jarvis-module-hero jarvis-accent-violet" data-jarvis-module="settings" aria-label="WhatsApp">
      <div class="jarvis-module-hero__identity"><span class="jarvis-module-hero__glyph" aria-hidden="true">✦</span><div>
        <div class="jarvis-module-hero__eyebrow">JARVIS ONE <span aria-hidden="true">/</span> SETTINGS</div>
        <h1>WhatsApp</h1>
      </div></div>
      <span class="jarvis-module-hero__section">Settings Workspace</span>
    </header>

    <h2 class="text-xl font-semibold">WhatsApp connection</h2>
    @if (session('status'))<p class="rounded bg-green-50 p-3 text-sm text-green-700">{{ session('status') }}</p>@endif
    @if ($errors->any())<div class="rounded bg-red-50 p-3 text-sm text-red-700">@foreach ($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div>@endif
    <section class="max-w-xl space-y-3 rounded-lg bg-white p-5 shadow">
        @if ($link)
            <p class="text-sm">Linked · {{ \Illuminate\Support\Carbon::parse($link->verified_at)->diffForHumans() }}</p>
            <p class="text-sm text-gray-600">Credits for questions and voice messages sent from WhatsApp are deducted from this workspace’s wallet. Send <code>/new</code> to start a new chat. You can send text or a voice note; a voice note like “run &lt;workflow name&gt;” starts that workflow.</p>
            <form method="POST" action="{{ route('whatsapp.unlink') }}" onsubmit="return confirm('Unlink WhatsApp?')">
                @csrf @method('DELETE')
                <x-button>Unlink</x-button>
            </form>
        @elseif (! $bot)
            <p class="text-sm text-gray-600">WhatsApp has not been enabled yet. Tell the platform admin.</p>
        @else
            @if ($code)
                <div class="space-y-2 rounded border border-indigo-200 bg-indigo-50 p-4">
                    <p class="text-sm">Your code (10 minutes, works only once):</p>
                    <p class="select-all text-2xl font-mono font-semibold tracking-widest">{{ $code }}</p>
                    <p class="text-sm">Open WhatsApp, message @if ($bot->bot_username)<strong>{{ $bot->bot_username }}</strong>@else the CANAI number @endif and send:<br><code>/link {{ $code }}</code></p>
                </div>
            @else
                <p class="text-sm text-gray-600">Link your WhatsApp to command CANAI by text or voice note. Get a code with the button below, then send it to the CANAI number.</p>
            @endif
            <form method="POST" action="{{ route('whatsapp.code') }}">@csrf <x-button>{{ $code ? 'Get a new code' : 'Get code' }}</x-button></form>
        @endif
    </section>
</x-layouts.app>
