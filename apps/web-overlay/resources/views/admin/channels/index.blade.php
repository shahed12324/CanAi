<x-layouts.admin title="Chat channels" :crumbs="['Chat channels' => null]">
    {{-- JARVIS ONE: page-specific visual identity; existing actions preserved --}}
    <header class="jarvis-module-hero jarvis-accent-violet" data-jarvis-module="admin" aria-label="Chat channels">
      <div class="jarvis-module-hero__identity"><span class="jarvis-module-hero__glyph" aria-hidden="true">✦</span><div>
        <div class="jarvis-module-hero__eyebrow">JARVIS ONE <span aria-hidden="true">/</span> ADMIN</div>
        <h1>Chat channels</h1><p>Manage your platform, permissions and operations.</p>
      </div></div>
      <span class="jarvis-module-hero__section">Admin Workspace</span>
    </header>
    <h1 class="text-xl font-semibold">Chat channels (Telegram &amp; WhatsApp)</h1>
    @if (session('status'))<p class="rounded bg-green-50 p-3 text-sm text-green-700">{{ session('status') }}</p>@endif
    @if ($errors->any())<div class="rounded bg-red-50 p-3 text-sm text-red-700">@foreach ($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div>@endif

    <div class="grid gap-4 sm:grid-cols-4">
        <div class="rounded-lg bg-white p-4 shadow"><p class="text-xs text-gray-500">Events in last 24 hours</p><p class="text-2xl font-semibold">{{ number_format($summary->total) }}</p></div>
        <div class="rounded-lg bg-white p-4 shadow"><p class="text-xs text-gray-500">Successful</p><p class="text-2xl font-semibold">{{ number_format($summary->ok) }}</p></div>
        <div class="rounded-lg bg-white p-4 shadow"><p class="text-xs text-gray-500">Ignored</p><p class="text-2xl font-semibold">{{ number_format($summary->ignored) }}</p></div>
        <div class="rounded-lg bg-white p-4 shadow"><p class="text-xs text-gray-500">Failed</p><p class="text-2xl font-semibold">{{ number_format($summary->failed) }}</p></div>
    </div>

    <section class="rounded-lg bg-white p-5 shadow">
        <h2 class="mb-2 font-medium">Bot</h2>
        @forelse ($accounts as $a)
            <div class="space-y-2 border-t py-3 text-sm">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div><strong>{{ $a->name }}</strong> @if ($a->bot_username)<span class="text-gray-500">{{ $a->channel === 'whatsapp' ? '' : '@' }}{{ $a->bot_username }}</span>@endif
                        <span class="ml-2 rounded bg-blue-50 px-2 py-0.5 text-xs text-blue-800">{{ $a->channel === 'whatsapp' ? 'WhatsApp' : 'Telegram' }}</span>
                        <span class="ml-2 rounded px-2 py-0.5 text-xs {{ $a->status === 'active' ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-700' }}">{{ $a->status === 'active' ? 'Active' : 'Closed' }}</span>
                        <span class="ml-2 text-gray-500">Linked users: {{ (int) ($links[$a->id] ?? 0) }}</span></div>
                    <div class="flex flex-wrap gap-2">
                        @if ($a->status === 'active')
                            @if ($a->channel === 'telegram')
                            <form method="POST" action="{{ route('admin.channels.check', $a->id) }}">@csrf <x-button>Telegram-View status of</x-button></form>
                            <form method="POST" action="{{ route('admin.channels.rotate', $a->id) }}" onsubmit="return confirm('A new secret will be set. Continue?')">@csrf <x-button>Secret Change</x-button></form>
                            @endif
                            <form method="POST" action="{{ route('admin.channels.deactivate', $a->id) }}" onsubmit="return confirm('Turn off the bot?')">@csrf <x-button>Deactivate</x-button></form>
                        @else
                            <form method="POST" action="{{ route('admin.channels.activate', $a->id) }}">@csrf <x-button>Activate</x-button></form>
                        @endif
                    </div>
                </div>
                @if ($a->channel === 'whatsapp' && isset($wa[$a->id]))
                    <div class="rounded bg-gray-50 p-3 text-xs">
                        <p>In Meta → WhatsApp → Configuration → Webhook set:</p>
                        <p>Callback URL: <code class="select-all">{{ $wa[$a->id]['callback'] }}</code></p>
                        <p>Verify token: <code class="select-all">{{ $wa[$a->id]['verify'] }}</code></p>
                        <p>Subscribe to the <strong>messages</strong> field.</p>
                    </div>
                @endif
                <p class="text-gray-600">Last webhook: {{ $a->last_webhook_at ? \Illuminate\Support\Carbon::parse($a->last_webhook_at)->diffForHumans() : '—' }}
                    @if ($a->last_error) · <span class="text-red-700">Last error: {{ $a->last_error }} ({{ \Illuminate\Support\Carbon::parse($a->last_error_at)->diffForHumans() }})</span>@endif</p>
            </div>
        @empty<p class="text-sm text-gray-500">No bots.</p>@endforelse
    </section>

    <section class="max-w-xl space-y-3 rounded-lg bg-white p-5 shadow">
        <h2 class="font-medium">Add a new bot</h2>
        <p class="text-sm text-gray-600">Telegram-Create a bot from &#64;BotFather and get the token. The token is stored encrypted and is not shown again.</p>
        <form method="POST" action="{{ route('admin.channels.store') }}" class="space-y-3" autocomplete="off">
            @csrf
            <x-field name="name" label="Name" :value="old('name')" required />
            <x-field name="token" label="Bot token" type="password" required />
            <x-button>Verify and add</x-button>
        </form>
    </section>

    <section class="max-w-xl space-y-3 rounded-lg bg-white p-5 shadow">
        <h2 class="font-medium">Add a WhatsApp number (Cloud API)</h2>
        <p class="text-sm text-gray-600">Needs a Meta developer app with WhatsApp enabled. Secrets are stored encrypted and never shown again (except the verify token above).</p>
        <form method="POST" action="{{ route('admin.channels.store_whatsapp') }}" class="space-y-3" autocomplete="off">
            @csrf
            <x-field name="name" label="Name" :value="old('name')" required />
            <x-field name="phone_number_id" label="Phone number ID" :value="old('phone_number_id')" required />
            <x-field name="display_number" label="Display number (optional)" :value="old('display_number')" />
            <x-field name="access_token" label="Permanent access token" type="password" required />
            <x-field name="app_secret" label="App secret" type="password" required />
            <x-button>Add number</x-button>
        </form>
    </section>

    <section class="rounded-lg bg-white p-5 shadow">
        <h2 class="mb-2 font-medium">Recent events (no message text is stored)</h2>
        <table class="min-w-full text-sm"><thead class="text-left text-gray-600"><tr><th class="py-1">Period</th><th>Bot</th><th>Type</th><th>Result</th><th>Reason</th><th>Attempt</th></tr></thead><tbody>
            @forelse ($events as $e)<tr class="border-t"><td class="py-1">{{ \Illuminate\Support\Carbon::parse($e->received_at)->diffForHumans() }}</td><td>{{ $e->bot }}</td><td>{{ $e->kind }}</td><td>{{ $e->outcome }}</td><td><code>{{ $e->error_code }}</code></td><td>{{ $e->attempts }}</td></tr>
            @empty<tr><td colspan="6" class="py-3 text-gray-500">Nothing.</td></tr>@endforelse
        </tbody></table>
    </section>
</x-layouts.admin>
