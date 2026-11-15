<x-layouts.app title="Sender addresses">
    {{-- JARVIS ONE: page-specific visual identity; existing actions preserved --}}
    <header class="jarvis-module-hero jarvis-accent-violet" data-jarvis-module="outreach" aria-label="Sender addresses">
      <div class="jarvis-module-hero__identity"><span class="jarvis-module-hero__glyph" aria-hidden="true">✦</span><div>
        <div class="jarvis-module-hero__eyebrow">JARVIS ONE <span aria-hidden="true">/</span> OUTREACH</div>
        <h1>Sender addresses</h1>
      </div></div>
      <span class="jarvis-module-hero__section">Outreach Workspace</span>
    </header>

    <h2 class="text-xl font-semibold">Sender address (SMTP)</h2>
    @include('outreach._nav')
    <section class="space-y-2 rounded-lg bg-white p-5 shadow">
        @forelse ($accounts as $a)
            <div class="flex flex-wrap items-center justify-between gap-2 border-t py-2 text-sm first:border-0">
                <span><strong>{{ $a->name }}</strong> · {{ $a->from_email }} <span class="text-gray-500">({{ $a->provider === 'smtp' ? $a->host.':'.$a->port.', '.$a->security : ($a->provider === 'gmail' ? 'Gmail API' : 'Microsoft Graph') }})</span>
                    @if ($a->last_error)<br><span class="text-xs text-red-600">⚠ {{ $errorBn[$a->last_error] ?? $a->last_error }}</span>@endif</span>
                <span class="flex items-center gap-3 text-xs text-gray-600">Daily limit {{ $a->daily_limit }} · Start {{ $a->started }} · {{ $a->status === 'active' ? 'Active' : 'Closed' }}
                    @if ($canManage)<form method="POST" action="{{ route('outreach.accounts.toggle', trim($a->id)) }}">@csrf<button class="text-indigo-600">{{ $a->status === 'active' ? 'Deactivate' : 'Activate' }}</button></form>@endif</span>
            </div>
        @empty<p class="text-sm text-gray-500">No sender address yet.</p>@endforelse
        <p class="text-xs text-gray-500">A new address sends at most 5 mails on the first day, rising by 3 each day, never above the daily limit you set.</p>
    </section>
    @error('oauth')<p class="rounded bg-red-50 p-3 text-sm text-red-700">{{ $message }}</p>@enderror
    @if ($canManage)
    <section class="max-w-2xl space-y-2 rounded-lg bg-white p-5 shadow">
        <h3 class="font-medium">Gmail or connect Outlook (recommended)</h3>
        <p class="text-xs text-gray-600">No password is needed; you grant permission and can withdraw it anytime from your Google/Microsoft account. Permissions needed: sending mail and (to catch replies/bounces) reading the inbox. We see only the sender name, subject and a short snippet, and never delete any mail.</p>
        <div class="flex flex-wrap gap-2">
            @foreach (['gmail' => 'Gmail Connect', 'graph' => 'Outlook / Microsoft 365 Connect'] as $pv => $label)
                <form method="POST" action="{{ route('outreach.oauth.start', $pv) }}">@csrf<button @disabled(! ($oauth[$pv] ?? false)) class="rounded border px-3 py-2 text-sm {{ ($oauth[$pv] ?? false) ? 'text-indigo-700' : 'cursor-not-allowed text-gray-400' }}">{{ $label }}</button></form>
            @endforeach
        </div>
        @if (! ($oauth['gmail'] ?? false) || ! ($oauth['graph'] ?? false))<p class="text-xs text-amber-700">A greyed-out button means its OAuth key is not set in the server’s .env (ENVIRONMENT_SETUP.md see).</p>@endif
    </section>
    <section class="max-w-2xl space-y-2 rounded-lg bg-white p-5 shadow">
        <h3 class="font-medium">Or add with SMTP</h3>
        <form method="POST" action="{{ route('outreach.accounts.store') }}" class="space-y-2" autocomplete="off">@csrf
            <x-field name="name" label="Name (only for you)" :value="old('name')" required />
            <x-field name="from_email" type="email" label="Sender email" :value="old('from_email')" required />
            <x-field name="from_name" label="Sender name (optional)" :value="old('from_name')" />
            <x-field name="host" label="SMTP Host (for example smtp.gmail.com)" :value="old('host')" required />
            <div class="grid grid-cols-2 gap-2"><x-field name="port" type="number" label="Port (465 or 587)" :value="old('port', 465)" required />
                <div><label for="security" class="block text-sm font-medium text-gray-700">Security</label><select id="security" name="security" class="w-full rounded-md border border-gray-300 px-3 py-2"><option value="tls">TLS (465)</option><option value="starttls" @selected(old('security') === 'starttls')>STARTTLS (587)</option></select></div></div>
            <x-field name="username" label="Username" :value="old('username')" required autocomplete="off" />
            <x-field name="password" type="password" label="Password / app password" required autocomplete="new-password" />
            <details class="rounded border p-2 text-sm"><summary class="cursor-pointer font-medium text-gray-700">IMAP to catch replies/bounces automatically (optional)</summary>
                <p class="mt-1 text-xs text-gray-500">The same username and password are used; the inbox is only read (nothing is deleted/moved/"Read" marked). If left empty you must mark replies by hand.</p>
                <div class="grid gap-2 sm:grid-cols-3"><x-field name="imap_host" label="IMAP Host" :value="old('imap_host')" placeholder="imap.example.com" /><x-field name="imap_port" type="number" label="Port" :value="old('imap_port', 993)" />
                    <div><label for="imap_security" class="block text-sm font-medium text-gray-700">Security</label><select id="imap_security" name="imap_security" class="w-full rounded-md border border-gray-300 px-3 py-2"><option value="tls">TLS (993)</option><option value="starttls" @selected(old('imap_security') === 'starttls')>STARTTLS (143)</option></select></div></div></details>
            <x-field name="daily_limit" type="number" label="Max mails per day" :value="old('daily_limit', 20)" required />
            <p class="text-xs text-gray-500">The password is stored encrypted and is not shown again.</p>
            <x-button>Add</x-button>
        </form>
    </section>
    @endif
    <section class="max-w-2xl space-y-2 rounded-lg bg-white p-5 shadow">
        <h3 class="font-medium">SPF / DKIM / DMARC Test</h3>
        <form method="POST" action="{{ route('outreach.dns') }}" class="grid gap-2 sm:grid-cols-3">@csrf
            <x-field name="domain" label="Domain (agency.com)" :value="old('domain')" required />
            <x-field name="selector" label="DKIM selector (optional)" :value="old('selector')" />
            <div class="flex items-end"><x-button>Check</x-button></div>
        </form>
        @if ($dns)
            <div class="mt-2 text-sm"><p class="font-medium">{{ $dns['domain'] }}</p>
                @foreach (['spf' => 'SPF', 'dkim' => 'DKIM', 'dmarc' => 'DMARC'] as $k => $label)
                    <p><span class="inline-block w-16 font-medium">{{ $label }}</span>
                        <span class="{{ $dns['result'][$k]['status'] === 'ok' ? 'text-green-700' : ($dns['result'][$k]['status'] === 'warn' ? 'text-amber-700' : 'text-red-700') }}">{{ $dns['result'][$k]['status'] === 'ok' ? '✓' : ($dns['result'][$k]['status'] === 'warn' ? '!' : '✗') }} {{ $dns['result'][$k]['note'] }}</span></p>
                @endforeach
                <p class="mt-1 text-xs text-gray-500">This only looks at DNS records; it is not a guarantee of reaching the inbox.</p></div>
        @endif
    </section>
</x-layouts.app>
