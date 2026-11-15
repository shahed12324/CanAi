<x-layouts.app title="Security">
    {{-- JARVIS ONE: page-specific visual identity; existing actions preserved --}}
    <header class="jarvis-module-hero jarvis-accent-violet" data-jarvis-module="settings" aria-label="Security">
      <div class="jarvis-module-hero__identity"><span class="jarvis-module-hero__glyph" aria-hidden="true">✦</span><div>
        <div class="jarvis-module-hero__eyebrow">JARVIS ONE <span aria-hidden="true">/</span> SETTINGS</div>
        <h1>Security</h1>
      </div></div>
      <span class="jarvis-module-hero__section">Settings Workspace</span>
    </header>

    <h2 class="text-xl font-semibold">Security</h2>

    <section class="space-y-3 rounded-lg bg-white p-5 shadow">
        <h3 class="font-medium">Two-step verification (2FA)</h3>
        @if (! $user->two_factor_secret)
            <p class="text-sm text-gray-600">Is off.</p>
            <form method="POST" action="{{ url('/user/two-factor-authentication') }}">
                @csrf
                <x-button>Activate</x-button>
            </form>
        @elseif (! $user->two_factor_confirmed_at)
            <p class="text-sm text-gray-700">Scan the QR code in your authenticator app and confirm with the 6-digit code.</p>
            <div class="w-48">{!! $user->twoFactorQrCodeSvg() !!}</div>
            <p class="text-sm text-gray-600">Setup key: <code>{{ decrypt($user->two_factor_secret) }}</code></p>
            <form method="POST" action="{{ url('/user/confirmed-two-factor-authentication') }}" class="max-w-xs space-y-3">
                @csrf
                <x-field name="code" label="Code" inputmode="numeric" autocomplete="one-time-code" required />
                <x-button>Confirm</x-button>
            </form>
        @else
            <p class="text-sm text-green-700">Is on.</p>
            <div>
                <p class="mb-1 text-sm font-medium">Recovery codes (keep them safe; each works only once)</p>
                <ul class="grid grid-cols-2 gap-1 font-mono text-sm sm:grid-cols-4">
                    @foreach (json_decode(decrypt($user->two_factor_recovery_codes), true) as $code)
                        <li>{{ $code }}</li>
                    @endforeach
                </ul>
            </div>
            <div class="flex gap-3">
                <form method="POST" action="{{ url('/user/two-factor-recovery-codes') }}">
                    @csrf
                    <x-button variant="ghost">New recovery codes</x-button>
                </form>
                <form method="POST" action="{{ url('/user/two-factor-authentication') }}">
                    @csrf
                    @method('DELETE')
                    <x-button variant="danger">Deactivate</x-button>
                </form>
            </div>
        @endif
    </section>

    <section class="space-y-3 rounded-lg bg-white p-5 shadow">
        <div class="flex items-center justify-between">
            <h3 class="font-medium">Active sessions</h3>
            @if ($sessions->count() > 1)
                <form method="POST" action="{{ route('security.sessions.others') }}">
                    @csrf
                    @method('DELETE')
                    <x-button variant="ghost">Close all other sessions</x-button>
                </form>
            @endif
        </div>
        <ul class="divide-y text-sm">
            @foreach ($sessions as $s)
                <li class="flex items-center justify-between gap-3 py-2">
                    <div class="min-w-0">
                        <p class="truncate">{{ $s->ip_address }} — {{ \Illuminate\Support\Str::limit((string) $s->user_agent, 70) }}</p>
                        <p class="text-gray-500">Latest: {{ \Illuminate\Support\Carbon::createFromTimestamp($s->last_activity)->diffForHumans() }}</p>
                    </div>
                    @if ($s->id === $currentSession)
                        <span class="rounded bg-green-50 px-2 py-1 text-green-700">This device</span>
                    @else
                        <form method="POST" action="{{ route('security.sessions.destroy', ['id' => $s->id]) }}">
                            @csrf
                            @method('DELETE')
                            <x-button variant="ghost">Deactivate</x-button>
                        </form>
                    @endif
                </li>
            @endforeach
        </ul>
    </section>

    <section class="space-y-3 rounded-lg bg-white p-5 shadow">
        <h3 class="font-medium">API Token / device</h3>
        @forelse ($tokens as $t)
            <div class="flex items-center justify-between gap-3 text-sm">
                <div>
                    <p>{{ $t->name }}</p>
                    <p class="text-gray-500">
                        Last used: {{ $t->last_used_at ? \Illuminate\Support\Carbon::parse($t->last_used_at)->diffForHumans() : 'Never' }}
                    </p>
                </div>
                <form method="POST" action="{{ route('security.tokens.destroy', ['id' => $t->id]) }}">
                    @csrf
                    @method('DELETE')
                    <x-button variant="danger">Cancel</x-button>
                </form>
            </div>
        @empty
            <p class="text-sm text-gray-600">No tokens.</p>
        @endforelse
    </section>
</x-layouts.app>
