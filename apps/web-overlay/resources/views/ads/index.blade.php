<x-layouts.app title="Meta Ad">
    {{-- JARVIS ONE: page-specific visual identity; existing actions preserved --}}
    <header class="jarvis-module-hero jarvis-accent-violet" data-jarvis-module="ads" aria-label="Meta Ad">
      <div class="jarvis-module-hero__identity"><span class="jarvis-module-hero__glyph" aria-hidden="true">✦</span><div>
        <div class="jarvis-module-hero__eyebrow">JARVIS ONE <span aria-hidden="true">/</span> ADS</div>
        <h1>Meta Ad</h1><p>Plan campaigns, review creatives and manage ad performance.</p>
      </div></div>
      <span class="jarvis-module-hero__section">Ads Workspace</span>
    </header>

    <h2 class="text-xl font-semibold">Meta Ad</h2>
    <p class="mt-1 text-sm"><a class="rounded bg-indigo-600 px-3 py-1 text-white" href="{{ route('ads.drafts') }}">Ad drafts and approval →</a></p>
    <p class="mt-1 rounded bg-amber-50 p-3 text-sm text-amber-900">This shows campaigns and results taken from Meta. You cannot create, change or spend on any ad from this page. Numbers are in the account’s own currency and refresh once an hour; "Results" are estimates and may not match Ads Manager’s "Results"-figures.</p>
    @if ($errors->any())<p class="mt-2 rounded bg-red-50 p-3 text-sm text-red-700">{{ $errors->first() }}</p>@endif
    @if (session('status'))<p class="mt-2 rounded bg-green-50 p-3 text-sm text-green-700">{{ session('status') }}</p>@endif
    @if (session('error'))<p class="mt-2 rounded bg-red-50 p-3 text-sm text-red-700">{{ session('error') }}</p>@endif

    <section class="mt-3 rounded-lg bg-white p-4 text-sm shadow"><h3 class="font-semibold">Connected accounts</h3>
        @forelse ($accounts as $a)
            <div class="mt-2 flex flex-wrap items-center gap-2 border-t pt-2"><b>{{ $a['name'] }}</b> <span class="text-xs text-gray-500">act_{{ $a['external_id'] }} · {{ $a['currency'] }}</span>
                <span class="rounded px-2 text-xs {{ $a['status'] === 'active' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' }}">{{ $a['status'] === 'active' ? 'Active' : 'Connection problem — reconnect' }}</span>
                <span class="text-xs text-gray-500">{{ $a['last_synced_at'] ? 'Last sync: '.$a['last_synced_at'] : 'Not synced yet' }}</span>
                @if ($a['sync_error'])<span class="text-xs text-red-700">{{ $a['sync_error'] }}</span>@endif
                @if ($can_connect)<form method="POST" action="{{ route('ads.pixel', $a['id']) }}" class="flex items-center gap-1">@csrf<input name="pixel_id" value="{{ $a['pixel_id'] }}" placeholder="Pixel ID (for sales)" inputmode="numeric" class="w-40 rounded border px-2 py-1 text-xs"><button class="rounded border px-2 py-1 text-xs">Save</button></form>@endif
                @if ($can_connect)<form method="POST" action="{{ route('ads.disconnect', $a['id']) }}" onsubmit="return confirm('Remove this account? The copied numbers here will be deleted; nothing changes in Meta.')" class="ml-auto">@csrf<button class="rounded border px-2 py-1 text-red-700">Remove</button></form>@endif</div>
        @empty<p class="mt-1 text-gray-500">No account connected yet.</p>@endforelse
    </section>

    <section class="mt-3 rounded-lg bg-white p-4 text-sm shadow">
        <h2 class="font-semibold">Optimization suggestions <span class="text-xs font-normal text-gray-500">(rule-based from the copied results of the last 7 days; not AI)</span></h2>
        @forelse ($advice as $s)
            <div class="mt-2 border-t pt-2"><span class="rounded px-2 text-xs {{ $s['kind'] === 'pause' ? 'bg-red-100 text-red-800' : 'bg-green-100 text-green-800' }}">{{ $s['kind'] === 'pause' ? 'Pause' : 'Consider increasing the budget' }}</span> <b>{{ $s['name'] }}</b> <span class="text-xs text-gray-500">{{ $acc_names[$s['account_id']] ?? '' }}</span>
                <p class="text-xs text-gray-700">{{ $s['why'] }}</p>
                @if ($s['kind'] === 'pause' && $can_approve)<form method="POST" action="{{ route('ads.pause', $s['account_id']) }}" onsubmit="return confirm('Meta-the campaign will be set to PAUSED. Sure?')" class="mt-1">@csrf<input type="hidden" name="campaign" value="{{ $s['campaign'] }}"><input type="hidden" name="reason" value="{{ $s['why'] }}"><button class="rounded bg-red-600 px-3 py-1 text-xs text-white">Approve and pause</button></form>
                @elseif ($s['kind'] === 'scale')<p class="mt-1 text-xs text-gray-500">We do not increase budgets — do it yourself in Ads Manager, within the approved limit and in small steps (for example ≤20%).</p>@endif</div>
        @empty<p class="mt-1 text-gray-500">No suggestions now (needs at least 3 days of spend and campaigns with enough clicks/comparison).</p>@endforelse
        @if ($pauses !== [])<p class="mt-3 text-xs font-semibold text-gray-600">Recent pause requests</p>@foreach ($pauses as $p)<p class="text-xs text-gray-600">{{ $p['name'] }} — {{ ['requested' => 'Waiting', 'running' => 'Running', 'done' => 'Paused', 'failed' => 'Not done'][$p['status']] }}@if ($p['error']) · {{ $p['error'] }}@endif</p>@endforeach @endif
    </section>

    <div class="mt-3 flex gap-2 text-sm">@foreach ([7, 30] as $d)<a class="rounded border px-3 py-1 {{ $d === $days ? 'bg-indigo-600 text-white' : '' }}" href="{{ route('ads.index', ['days' => $d]) }}">{{ $d }} days</a>@endforeach</div>
    <section class="mt-3 rounded-lg bg-white p-4 text-sm shadow">
        <p>Total spend: <b>{{ number_format($totals['spend'], 2) }}</b> · Impressions: <b>{{ number_format($totals['impressions']) }}</b> · Clicks: <b>{{ number_format($totals['clicks']) }}</b> · Results (estimated): <b>{{ number_format($totals['results']) }}</b></p>
        @if (count($accounts) > 1)<p class="mt-1 text-xs text-gray-500">If multiple accounts have different currencies, summing total spend is meaningless — see per account below.</p>@endif
        @if ($rows === [])<p class="mt-2 text-gray-500">No campaigns to show (they will appear after sync).</p>
        @else
        <div class="overflow-x-auto"><table class="mt-2 w-full text-left"><thead><tr class="text-xs text-gray-500"><th>Campaign</th><th>Status</th><th>Expenses</th><th>Impressions</th><th>Clicks</th><th>Results*</th></tr></thead><tbody>
        @foreach ($rows as $r)<tr class="border-t"><td class="py-1">{{ $r['name'] }}<div class="text-xs text-gray-400">{{ $r['objective'] }}</div></td><td>{{ $r['status'] }}</td><td>{{ number_format((float) $r['spend'], 2) }}</td><td>{{ number_format($r['impressions']) }}</td><td>{{ number_format($r['clicks']) }}</td><td>{{ number_format($r['results']) }}</td></tr>@endforeach
        </tbody></table></div>@endif
    </section>

    @if ($can_connect)
    <section class="mt-3 rounded-lg bg-white p-4 text-sm shadow"><h3 class="font-semibold">Connect account (or new token)</h3>
        <form method="POST" action="{{ route('ads.connect') }}" autocomplete="off" class="mt-2 grid gap-3">@csrf
            <label class="grid gap-1">Ad account ID (digits, "act_" also works)<input name="account_id" maxlength="40" required value="{{ old('account_id') }}" class="rounded border px-2 py-1"></label>
            <label class="grid gap-1">Access Token<input type="password" name="token" maxlength="700" required autocomplete="off" class="rounded border px-2 py-1"></label>
            <div><button class="rounded bg-indigo-600 px-3 py-1 text-white">Verify and connect</button></div>
        </form>
        <div class="mt-3 rounded bg-amber-50 p-3 text-amber-900"><b>How to get a token</b>
            <ol class="ml-5 list-decimal">
                <li>business.facebook.com → Business Settings → Users → System Users-Create a System User in (or take a User Token from Graph API Explorer).</li>
                <li>Give that System User the ad account and grant permission: <code>ads_read</code> (for sync) and <code>ads_management</code> (for creating approved drafts in paused state). Without this permission on the token, creation will fail.</li>
                <li>"Generate token" and paste it here. System User tokens usually last long.</li>
            </ol>
            The token is verified and sealed, and is not shown again. <b>ads_management</b> Do not grant the permission now — ad creation comes in the next step, with separate approval.
        </div>
    </section>
    @endif
</x-layouts.app>
