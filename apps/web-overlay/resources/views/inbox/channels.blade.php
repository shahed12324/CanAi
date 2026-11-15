<x-layouts.app title="Channels">
    {{-- JARVIS ONE: page-specific visual identity; existing actions preserved --}}
    <header class="jarvis-module-hero jarvis-accent-violet" data-jarvis-module="inbox" aria-label="Channels">
      <div class="jarvis-module-hero__identity"><span class="jarvis-module-hero__glyph" aria-hidden="true">✦</span><div>
        <div class="jarvis-module-hero__eyebrow">JARVIS ONE <span aria-hidden="true">/</span> INBOX</div>
        <h1>Channels</h1>
      </div></div>
      <span class="jarvis-module-hero__section">Inbox Workspace</span>
    </header>

    <div class="flex flex-wrap items-center justify-between gap-2"><h2 class="text-xl font-semibold">Channels and chat widget</h2><a class="text-sm text-indigo-600" href="{{ route('inbox.index') }}">← Inbox</a></div>
    @if (session('status'))<p class="rounded bg-green-50 p-3 text-sm text-green-700">{{ session('status') }}</p>@endif
    @foreach ($channels as $ch)
        <section class="space-y-2 rounded-lg bg-white p-5 text-sm shadow">
            <h3 class="font-medium">{{ $ch['name'] }} <span class="ml-1 rounded bg-gray-100 px-2 py-0.5 text-xs font-normal">{{ $kindBn($ch['kind']) }}</span>
                <span class="ml-1 text-xs {{ $ch['status'] === 'active' ? 'text-green-700' : 'text-amber-700' }}">{{ $ch['status'] === 'active' ? 'Active' : 'Closed' }}</span></h3>
            @if ($ch['kind'] === 'web_widget')
                <p class="text-xs text-gray-600">Your site’s <code>&lt;body&gt;</code> at the end put this line:</p>
                <pre class="overflow-x-auto rounded bg-gray-900 p-3 text-xs text-green-300">&lt;script src="{{ rtrim(config('app.url'), '/') }}/js/jarvis-widget.js" data-key="{{ $ch['public_key'] }}" async&gt;&lt;/script&gt;</pre>
                @if ($canManage)
                <form method="POST" action="{{ route('inbox.channels.update', $ch['id']) }}" class="grid gap-2 sm:grid-cols-2">@csrf
                    <label class="text-xs">Name<input name="name" value="{{ $ch['name'] }}" required class="mt-1 block w-full rounded border px-2 py-1 text-sm"></label>
                    <label class="text-xs">Status<select name="status" class="mt-1 block w-full rounded border px-2 py-1 text-sm"><option value="active" @selected($ch['status'] === 'active')>Active</option><option value="paused" @selected($ch['status'] === 'paused')>Closed</option></select></label>
                    <label class="text-xs sm:col-span-2">Allowed websites (one per line; the widget works only on these sites)<textarea name="origins" rows="2" required class="mt-1 block w-full rounded border px-2 py-1 text-sm">{{ implode("\n", $ch['allowed_origins']) }}</textarea></label>
                    <label class="text-xs sm:col-span-2">Welcome message<input name="greeting" value="{{ $ch['greeting'] }}" maxlength="300" class="mt-1 block w-full rounded border px-2 py-1 text-sm"></label>
                    <div><button class="rounded bg-gray-800 px-3 py-1 text-white">Save</button></div>
                </form>
                @endif
            @else
                @if ($ch['kind'] !== 'telegram')
                    <p class="text-xs text-gray-600">Meta Set these two in Webhooks on the developer dashboard:</p>
                    <pre class="overflow-x-auto rounded bg-gray-900 p-3 text-xs text-green-300">Callback URL:  {{ $base }}/webhooks/inbox/{{ $ch['public_key'] }}
Verify token:  {{ $verify($ch['public_key']) }}</pre>
                    <p class="text-xs text-gray-600">Subscribe: {{ $ch['kind'] === 'whatsapp' ? 'messages' : 'messages (Messenger/Instagram)' }}. A reply can be sent only within 24 hours of the customer’s last message.</p>
                @else
                    <p class="text-xs text-gray-600">Telegram webhook It was set automatically.</p>
                @endif
                @if ($canManage)
                <form method="POST" action="{{ route('inbox.channels.update', $ch['id']) }}" class="flex flex-wrap items-end gap-2">@csrf
                    <label class="text-xs">Name<input name="name" value="{{ $ch['name'] }}" required class="mt-1 block rounded border px-2 py-1 text-sm"></label>
                    <label class="text-xs">Status<select name="status" class="mt-1 block rounded border px-2 py-1 text-sm"><option value="active" @selected($ch['status'] === 'active')>Active</option><option value="paused" @selected($ch['status'] === 'paused')>Closed</option></select></label>
                    <button class="rounded bg-gray-800 px-3 py-1 text-white">Save</button>
                </form>
                @endif
            @endif
        </section>
    @endforeach
    @if ($canManage)
    <section class="space-y-3 rounded-lg bg-white p-5 text-sm shadow"><h3 class="font-medium">New website chat</h3>
        <form method="POST" action="{{ route('inbox.channels.store') }}" class="grid gap-3 sm:grid-cols-2">@csrf
            <label class="text-xs">Name<input name="name" value="{{ old('name') }}" required class="mt-1 block w-full rounded border px-2 py-1 text-sm"></label>
            <label class="text-xs">Welcome message<input name="greeting" value="{{ old('greeting', 'Hello! How can I help?') }}" maxlength="300" class="mt-1 block w-full rounded border px-2 py-1 text-sm"></label>
            <label class="text-xs sm:col-span-2">Your website (https://yourshop.com — one per line)<textarea name="origins" rows="2" required class="mt-1 block w-full rounded border px-2 py-1 text-sm">{{ old('origins') }}</textarea></label>
            @error('origins')<p class="text-red-600 sm:col-span-2">{{ $message }}</p>@enderror
            <div class="sm:col-span-2"><button class="rounded bg-indigo-600 px-3 py-1 text-white">Create</button></div>
        </form>
    </section>
    <section class="space-y-3 rounded-lg bg-white p-5 text-sm shadow"><h3 class="font-medium">Messenger / Instagram / WhatsApp / Telegram Add</h3>
        <form method="POST" action="{{ route('inbox.channels.external') }}" class="grid gap-3 sm:grid-cols-2" x-data>@csrf
            <label class="text-xs">Platform<select name="kind" class="mt-1 block w-full rounded border px-2 py-1 text-sm"><option value="messenger">Messenger (Facebook Page)</option><option value="instagram">Instagram DM</option><option value="whatsapp">WhatsApp Cloud API</option><option value="telegram">Telegram Bot</option></select></label>
            <label class="text-xs">Name<input name="name" required maxlength="120" class="mt-1 block w-full rounded border px-2 py-1 text-sm"></label>
            <label class="text-xs">Page ID / Instagram account ID / WhatsApp Phone Number ID (Telegram-empty for)<input name="account" autocomplete="off" class="mt-1 block w-full rounded border px-2 py-1 text-sm"></label>
            <label class="text-xs">Access Token (Telegram-empty for)<input name="access_token" autocomplete="off" class="mt-1 block w-full rounded border px-2 py-1 text-sm"></label>
            <label class="text-xs">App Secret (Telegram-empty for)<input name="app_secret" autocomplete="off" class="mt-1 block w-full rounded border px-2 py-1 text-sm"></label>
            <label class="text-xs">Telegram Bot Token (@BotFather from)<input name="bot_token" autocomplete="off" class="mt-1 block w-full rounded border px-2 py-1 text-sm"></label>
            @error('account')<p class="text-red-600 sm:col-span-2">{{ $message }}</p>@enderror
            <div class="sm:col-span-2"><button class="rounded bg-indigo-600 px-3 py-1 text-white">Verify and add</button></div>
        </form>
        <p class="text-xs text-gray-600">The token is taken only once, stored encrypted, and never shown again. Comment replies are not available yet.</p>
    </section>
    @endif
</x-layouts.app>
