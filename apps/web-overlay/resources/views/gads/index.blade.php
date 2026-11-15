<x-layouts.app title="Google Ads">
    {{-- JARVIS ONE: page-specific visual identity; existing actions preserved --}}
    <header class="jarvis-module-hero jarvis-accent-violet" data-jarvis-module="gads" aria-label="Google Ads">
      <div class="jarvis-module-hero__identity"><span class="jarvis-module-hero__glyph" aria-hidden="true">✦</span><div>
        <div class="jarvis-module-hero__eyebrow">JARVIS ONE <span aria-hidden="true">/</span> GADS</div>
        <h1>Google Ads</h1><p>Manage Google Ads campaigns and creative drafts.</p>
      </div></div>
      <span class="jarvis-module-hero__section">Gads Workspace</span>
    </header>

    <h2 class="text-xl font-semibold">Google Ads</h2>
    <p class="mt-1 text-sm"><a class="rounded bg-indigo-600 px-3 py-1 text-white" href="{{ route('gads.drafts') }}">Search Ad drafts and approval →</a></p>
    <p class="mt-1 rounded bg-amber-50 p-3 text-sm text-amber-900">This shows campaigns and results taken from Google Ads. From this page you can only pause a campaign per a suggestion (PAUSED) — you cannot start, change budgets or spend. Numbers are in the account’s own currency and refresh once an hour; "Conversions" According to the conversion actions set in your Google Ads.</p>
    @if ($errors->any())<p class="mt-2 rounded bg-red-50 p-3 text-sm text-red-700">{{ $errors->first() }}</p>@endif
    @if (session('status'))<p class="mt-2 rounded bg-green-50 p-3 text-sm text-green-700">{{ session('status') }}</p>@endif
    @if (session('error'))<p class="mt-2 rounded bg-red-50 p-3 text-sm text-red-700">{{ session('error') }}</p>@endif

    <section class="mt-3 rounded-lg bg-white p-4 text-sm shadow"><h3 class="font-semibold">Connected accounts</h3>
        @forelse ($accounts as $a)
            <div class="mt-2 flex flex-wrap items-center gap-2 border-t pt-2"><b>{{ $a['name'] }}</b> <span class="text-xs text-gray-500">{{ substr($a['customer_id'], 0, 3) }}-{{ substr($a['customer_id'], 3, 3) }}-{{ substr($a['customer_id'], 6) }} · {{ $a['currency'] }}@if ($a['login_customer_id']) · Manager {{ $a['login_customer_id'] }}@endif</span>
                <span class="rounded px-2 text-xs {{ $a['status'] === 'active' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' }}">{{ $a['status'] === 'active' ? 'Active' : 'Connection problem — reconnect' }}</span>
                <span class="text-xs text-gray-500">{{ $a['last_synced_at'] ? 'Last sync: '.$a['last_synced_at'] : 'Not synced yet' }}</span>
                @if ($a['sync_error'])<span class="text-xs text-red-700">{{ $a['sync_error'] }}</span>@endif
                @if ($can_connect)<form method="POST" action="{{ route('gads.disconnect', $a['id']) }}" onsubmit="return confirm('Remove this account? The copied numbers here will be deleted; nothing changes in Google.')" class="ml-auto">@csrf<button class="rounded border px-2 py-1 text-red-700">Remove</button></form>@endif</div>
        @empty<p class="mt-1 text-gray-500">No account connected yet.</p>@endforelse
    </section>

    <section class="mt-3 rounded-lg bg-white p-4 text-sm shadow"><h3 class="font-semibold">Suggestions (last 7 days)</h3>
        <p class="text-xs text-gray-500">Rule-based, not AI. Only pausing is possible (with approval); we do not increase budgets or start campaigns. No suggestions are given for accounts with not a single conversion in a week (conversion tracking may not be set).</p>
        @forelse ($advice as $s)
            <div class="mt-2 border-t pt-2"><span class="rounded px-2 text-xs {{ $s['kind'] === 'pause' ? 'bg-red-100 text-red-800' : 'bg-green-100 text-green-800' }}">{{ $s['kind'] === 'pause' ? 'Pause' : 'Consider increasing the budget' }}</span> <b>{{ $s['name'] }}</b> <span class="text-xs text-gray-500">{{ $acc_names[$s['account_id']] ?? '' }}</span>
                <p class="text-xs text-gray-700">{{ $s['why'] }}</p>
                @if ($s['kind'] === 'pause' && $can_approve)<form method="POST" action="{{ route('gads.pause', $s['account_id']) }}" onsubmit="return confirm('Google Ads-the campaign will be set to PAUSED. Sure?')" class="mt-1">@csrf<input type="hidden" name="campaign" value="{{ $s['campaign'] }}"><input type="hidden" name="reason" value="{{ $s['why'] }}"><button class="rounded bg-red-600 px-3 py-1 text-xs text-white">Approve and pause</button></form>
                @elseif ($s['kind'] === 'scale')<p class="mt-1 text-xs text-gray-500">We do not increase budgets — do it yourself in Google Ads, in small steps (for example ≤20%).</p>@endif</div>
        @empty<p class="mt-1 text-gray-500">No suggestions now (needs at least 3 days of spend, campaigns with enough clicks/comparison and active conversion tracking).</p>@endforelse
        @if ($pauses !== [])<p class="mt-3 text-xs font-semibold text-gray-600">Recent pause requests</p>@foreach ($pauses as $p)<p class="text-xs text-gray-600">{{ $p['name'] }} — {{ ['requested' => 'Waiting', 'running' => 'Running', 'done' => 'Paused', 'failed' => 'Not done'][$p['status']] }}@if ($p['error']) · {{ $p['error'] }}@endif</p>@endforeach @endif
    </section>

    <div class="mt-3 flex gap-2 text-sm">@foreach ([7, 30] as $d)<a class="rounded border px-3 py-1 {{ $d === $days ? 'bg-indigo-600 text-white' : '' }}" href="{{ route('gads.index', ['days' => $d]) }}">{{ $d }} days</a>@endforeach</div>
    <section class="mt-3 rounded-lg bg-white p-4 text-sm shadow">
        <p>Total spend: <b>{{ number_format($totals['cost'], 2) }}</b> · Impressions: <b>{{ number_format($totals['impressions']) }}</b> · Clicks: <b>{{ number_format($totals['clicks']) }}</b> · Conversions: <b>{{ number_format($totals['conversions'], 1) }}</b></p>
        @if (count($accounts) > 1)<p class="mt-1 text-xs text-gray-500">If multiple accounts have different currencies, summing total spend is meaningless — view per account.</p>@endif
        @if ($rows === [])<p class="mt-2 text-gray-500">No campaigns to show (they will appear after sync).</p>
        @else
        <div class="overflow-x-auto"><table class="mt-2 w-full text-left"><thead><tr class="text-xs text-gray-500"><th>Campaign</th><th>Status</th><th>Expenses</th><th>Impressions</th><th>Clicks</th><th>Conversions</th></tr></thead><tbody>
        @foreach ($rows as $r)<tr class="border-t"><td class="py-1">{{ $r['name'] }}<div class="text-xs text-gray-400">{{ $r['channel'] }}</div></td><td>{{ $r['status'] }}</td><td>{{ number_format((float) $r['cost'], 2) }}</td><td>{{ number_format($r['impressions']) }}</td><td>{{ number_format($r['clicks']) }}</td><td>{{ number_format((float) $r['conversions'], 1) }}</td></tr>@endforeach
        </tbody></table></div>@endif
    </section>

    @if ($can_connect)
    <section class="mt-3 rounded-lg bg-white p-4 text-sm shadow"><h3 class="font-semibold">Connect account (or reconnect)</h3>
        @if (! $ready)<p class="mt-1 rounded bg-red-50 p-2 text-red-700">Google Ads keys are not set on this server (GOOGLE_ADS_DEVELOPER_TOKEN and OAuth client) — tell the admin.</p>@endif
        <form method="POST" action="{{ route('gads.start') }}" autocomplete="off" class="mt-2 grid gap-3">@csrf
            <label class="grid gap-1">Customer ID (10 digits, for example 123-456-7890)<input name="customer_id" maxlength="20" required value="{{ old('customer_id') }}" class="rounded border px-2 py-1"></label>
            <label class="grid gap-1">Manager (MCC) Customer ID — only when accessing through a manager account (optional)<input name="login_customer_id" maxlength="20" value="{{ old('login_customer_id') }}" class="rounded border px-2 py-1"></label>
            <div><button class="rounded bg-indigo-600 px-3 py-1 text-white" @disabled(! $ready)>Google Connect with</button></div>
        </form>
        <div class="mt-3 rounded bg-amber-50 p-3 text-amber-900"><b>Setup (admin, once)</b>
            <ol class="ml-5 list-decimal">
                <li>Get a Developer Token from the API Center of your Google Ads manager account → <code>GOOGLE_ADS_DEVELOPER_TOKEN</code>. For read-only "Explorer/Test" level runs on your own test account; a real account needs Basic Access approval.</li>
                <li>Google Cloud Enable the Google Ads API in the project; add to the OAuth client’s Redirect URI <code>{{ $redirect }}</code> (the client can be the same as YouTube’s).</li>
                <li>User "Google Connect with" When pressed you log in to Google and grant permission to view Google Ads only; we do not get your password, the token is sealed.</li>
            </ol></div>
    </section>
    @endif
</x-layouts.app>
