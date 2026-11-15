<x-layouts.app title="Ad drafts">
    {{-- JARVIS ONE: page-specific visual identity; existing actions preserved --}}
    <header class="jarvis-module-hero jarvis-accent-violet" data-jarvis-module="ads" aria-label="Ad drafts">
      <div class="jarvis-module-hero__identity"><span class="jarvis-module-hero__glyph" aria-hidden="true">✦</span><div>
        <div class="jarvis-module-hero__eyebrow">JARVIS ONE <span aria-hidden="true">/</span> ADS</div>
        <h1>Ad drafts</h1><p>Plan campaigns, review creatives and manage ad performance.</p>
      </div></div>
      <span class="jarvis-module-hero__section">Ads Workspace</span>
    </header>

    @php $locked = $d && $d['status'] !== 'draft'; $editable = $can_manage && ! $locked; $v = fn ($k, $def = '') => old($k, $d[$k] ?? $def); @endphp
    <p class="text-sm"><a class="text-indigo-600 hover:underline" href="{{ route('ads.drafts') }}">← Draft list</a></p>
    <h2 class="mt-1 text-xl font-semibold">{{ $d ? $d['name'] : 'New ad draft' }}</h2>
    <p class="mt-1 rounded bg-amber-50 p-3 text-sm text-amber-900">This is only a draft — <b>Meta-nothing is sent and no money is spent.</b> AI It only proposes copy and a starting audience; you decide the budget. Read and verify the AI’s text yourself (remove any wrong prices/claims).</p>
    @if ($errors->any())<p class="mt-2 rounded bg-red-50 p-3 text-sm text-red-700">{{ $errors->first() }}</p>@endif
    @if (session('status'))<p class="mt-2 rounded bg-green-50 p-3 text-sm text-green-700">{{ session('status') }}</p>@endif
    @if (session('error'))<p class="mt-2 rounded bg-red-50 p-3 text-sm text-red-700">{{ session('error') }}</p>@endif

    @if ($d)
    <section class="mt-3 rounded-lg bg-white p-4 text-sm shadow">
        <p>Status: <b>{{ \App\Ads\AdsText::STATUS[$d['status']] }}</b>@if ($d['approver']) · Approval: {{ $d['approver'] }} ({{ $d['approved_at'] }})@endif</p>
        @if ($d['reject_note'])<p class="mt-1 rounded bg-red-50 p-2 text-red-700">Return note: {{ $d['reject_note'] }}</p>@endif
        <div class="mt-2 flex flex-wrap gap-2">
            @if ($can_manage && $d['user_id'] === $me && $d['status'] === 'draft')<form method="POST" action="{{ route('ads.draft.submit', $d['id']) }}">@csrf<button class="rounded bg-indigo-600 px-3 py-1 text-white">Submit for approval</button></form>@endif
            @if ($can_manage && $d['user_id'] === $me && $d['status'] !== 'draft')<form method="POST" action="{{ route('ads.draft.reopen', $d['id']) }}">@csrf<button class="rounded border px-3 py-1">Return to draft</button></form>@endif
            @if ($can_manage && $d['user_id'] === $me && $d['status'] === 'draft')<form method="POST" action="{{ route('ads.draft.destroy', $d['id']) }}" onsubmit="return confirm('Delete this draft?')">@csrf<button class="rounded border px-3 py-1 text-red-700">Delete</button></form>@endif
        </div>
        @if ($can_approve && $d['status'] === 'pending')
        <form method="POST" action="{{ route('ads.draft.decide', $d['id']) }}" class="mt-3 grid gap-2 border-t pt-3">@csrf
            <p class="font-semibold">Approval: daily {{ number_format((float) $d['daily_budget'], 2) }} {{ $d['currency'] }} × {{ $d['days'] }} days = total maximum {{ number_format((float) $d['daily_budget'] * $d['days'], 2) }} {{ $d['currency'] }}
                @if ($caps) <span class="font-normal text-gray-500">(Limit: daily {{ number_format((float) $caps['max_daily'], 2) }}, Total {{ number_format((float) $caps['max_total'], 2) }})</span>@endif</p>
            <input name="note" maxlength="300" placeholder="Reason for returning (optional)" class="rounded border px-2 py-1">
            <div class="flex gap-2"><button name="decision" value="approve" class="rounded bg-green-600 px-3 py-1 text-white">Approve</button><button name="decision" value="reject" class="rounded border px-3 py-1">Return</button></div>
        </form>@endif
    </section>
    @endif

    @if ($editable)
    <section class="mt-3 rounded-lg bg-white p-4 text-sm shadow"><h3 class="font-semibold">AI Write with (uses credits)</h3>
        <label class="mt-1 grid gap-1">What is the product/offer? (write only true facts — AI will not invent prices or discounts)<textarea id="brief" maxlength="1500" rows="3" class="rounded border px-2 py-1"></textarea></label>
        <div class="mt-2 flex flex-wrap items-center gap-2"><select id="tone" class="rounded border px-2 py-1">@foreach (\App\Social\SocialText::TONES as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select>
            <button type="button" id="ai" class="rounded bg-indigo-600 px-3 py-1 text-white">Suggest copy</button><span id="ai-msg" class="text-xs text-gray-600"></span></div>
    </section>
    <form method="POST" action="{{ $d ? route('ads.draft.update', $d['id']) : route('ads.draft.store') }}" class="mt-3 grid gap-3 rounded-lg bg-white p-4 text-sm shadow">@csrf
        <label class="grid gap-1">Account<select name="account_id" id="f-account" class="rounded border px-2 py-1">@foreach ($accounts as $a)<option value="{{ $a['id'] }}" @selected($v('account_id') === $a['id'])>{{ $a['name'] }} ({{ $a['currency'] }})</option>@endforeach</select></label>
        <label class="grid gap-1">Campaign name<input name="name" id="f-name" maxlength="120" required value="{{ $v('name') }}" class="rounded border px-2 py-1"></label>
        <label class="grid gap-1">Objective<select name="objective" id="f-objective" class="rounded border px-2 py-1">@foreach (\App\Ads\AdsText::OBJECTIVES as $k => $l)<option value="{{ $k }}" @selected($v('objective', 'sales') === $k)>{{ $l }}</option>@endforeach</select></label>
        <label class="grid gap-1">Main text (max 1000)<textarea name="primary_text" id="f-primary" maxlength="1000" rows="5" class="rounded border px-2 py-1">{{ $v('primary_text') }}</textarea></label>
        <div class="grid gap-3 sm:grid-cols-2">
            <label class="grid gap-1">Headline (max 80, best within 40)<input name="headline" id="f-headline" maxlength="80" value="{{ $v('headline') }}" class="rounded border px-2 py-1"></label>
            <label class="grid gap-1">Short description (max 200)<input name="description" id="f-description" maxlength="200" value="{{ $v('description') }}" class="rounded border px-2 py-1"></label>
            <label class="grid gap-1">Button<select name="cta" id="f-cta" class="rounded border px-2 py-1">@foreach (\App\Ads\AdsText::CTAS as $k => $l)<option value="{{ $k }}" @selected($v('cta', 'LEARN_MORE') === $k)>{{ $l }}</option>@endforeach</select></label>
            <label class="grid gap-1">Link (https://… ; needed for sales/leads/visits)<input name="link_url" maxlength="500" value="{{ $v('link_url') }}" class="rounded border px-2 py-1"></label>
        </div>
        <fieldset class="grid gap-3 rounded border p-3 sm:grid-cols-5"><legend class="px-1 text-xs text-gray-500">Audience</legend>
            <label class="grid gap-1">Country<input name="country" maxlength="2" value="{{ $v('country', 'BD') }}" class="rounded border px-2 py-1"></label>
            <label class="grid gap-1">Age from<input name="age_min" id="f-age_min" inputmode="numeric" value="{{ $v('age_min', 18) }}" class="rounded border px-2 py-1"></label>
            <label class="grid gap-1">Age to<input name="age_max" id="f-age_max" inputmode="numeric" value="{{ $v('age_max', 65) }}" class="rounded border px-2 py-1"></label>
            <label class="grid gap-1">Gender<select name="gender" id="f-gender" class="rounded border px-2 py-1">@foreach (\App\Ads\AdsText::GENDERS as $k => $l)<option value="{{ $k }}" @selected($v('gender', 'all') === $k)>{{ $l }}</option>@endforeach</select></label>
            <label class="grid gap-1 sm:col-span-5">Interests (comma-separated, optional)<input name="interests" id="f-interests" maxlength="300" value="{{ $v('interests') }}" class="rounded border px-2 py-1"></label></fieldset>
        <fieldset class="grid gap-3 rounded border p-3 sm:grid-cols-3"><legend class="px-1 text-xs text-gray-500">Budget (you decide — not AI)</legend>
            <label class="grid gap-1">Daily budget<input name="daily_budget" inputmode="decimal" required value="{{ $v('daily_budget') }}" class="rounded border px-2 py-1"></label>
            <label class="grid gap-1">How many days (1–60)<input name="days" inputmode="numeric" required value="{{ $v('days', 7) }}" class="rounded border px-2 py-1"></label>
            <label class="grid gap-1">Start date (required for creating in Meta)<input type="date" name="start_date" value="{{ $v('start_date') }}" class="rounded border px-2 py-1"></label>
            @if ($caps)<p class="text-xs text-gray-500 sm:col-span-3">Limit: daily {{ number_format((float) $caps['max_daily'], 2) }}, Total {{ number_format((float) $caps['max_total'], 2) }}</p>@else<p class="text-xs text-red-700 sm:col-span-3">Spending limit is not set — the owner/admin must "Draft list" set a limit from, otherwise nothing can be submitted.</p>@endif</fieldset>
        <div><button class="rounded bg-indigo-600 px-3 py-1 text-white">Save</button></div>
    </form>
    <script>
    (function () {
        var $ = function (i) { return document.getElementById(i); }, rid = @json($rid), csrf = document.querySelector('input[name=_token]').value;
        $('ai').addEventListener('click', function () {
            var b = $('ai'), m = $('ai-msg'); if (!$('brief').value.trim()) { m.textContent = 'Write what the product/offer is.'; return; }
            b.disabled = true; m.textContent = 'Writing…';
            fetch(@json(route('ads.draft.ai')), {method: 'POST', headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf},
                body: JSON.stringify({rid: rid, brief: $('brief').value, objective: $('f-objective').value, tone: $('tone').value})})
                .then(function (r) { return r.json().then(function (j) { return [r.ok, j]; }); })
                .then(function (x) {
                    if (x[0] && x[1].ok) {
                        var j = x[1]; [['name','name'],['primary','primary_text'],['headline','headline'],['description','description'],['cta','cta'],['age_min','age_min'],['age_max','age_max'],['gender','gender'],['interests','interests']].forEach(function (p) {
                            if (j[p[1]] !== undefined && j[p[1]] !== '' && !(p[0] === 'name' && $('f-name').value)) { $('f-' + p[0]).value = j[p[1]]; } });
                        m.textContent = 'The proposal has been placed in the form — read, verify and save.';
                        rid = Array.from({length: 26}, function () { return '0123456789abcdefghijklmnopqrstuvwxyz'[Math.floor(Math.random() * 36)]; }).join('');
                    } else { m.textContent = (x[1] && x[1].message) || 'Could not be done.'; }
                }).catch(function () { m.textContent = 'Could not be reached.'; }).then(function () { b.disabled = false; });
        });
    })();
    </script>
    @elseif ($d)
    <section class="mt-3 rounded-lg bg-white p-4 text-sm shadow">
        <p><b>{{ $d['name'] }}</b> · {{ \App\Ads\AdsText::OBJECTIVES[$d['objective']] ?? '' }} · {{ $d['account_name'] }}</p>
        @if ($d['launch_state'])
        <div class="mt-2 rounded border p-2 {{ $d['launch_state'] === 'failed' ? 'border-red-300 bg-red-50' : 'bg-gray-50' }}">
            @if ($d['launch_state'] === 'created')<p class="text-green-800">Meta-was created in paused (PAUSED) state ({{ $d['launched_at'] }}). <b> no money is being spent yet</b> — To start it, go to Meta Ads Manager and "Active" do it yourself.</p>
            @elseif ($d['launch_state'] === 'launching')<p>Meta-is being created… (in paused state)</p>
            @else<p class="text-red-700">Not created: {{ $d['launch_error'] }}</p>
                @if ($d['launch_unknown'])<p class="mt-1 text-xs text-red-700">Result unknown — first check in Meta Ads Manager whether anything was created.</p>@endif
                @if ($can_manage && $d['user_id'] === $me)<form method="POST" action="{{ route('ads.draft.retry', $d['id']) }}" class="mt-2">@csrf
                    @if ($d['launch_unknown'])<label class="flex items-center gap-2 text-xs"><input type="checkbox" name="confirm" value="1"> I checked Ads Manager; I understand the risk of duplicates if I retry</label>@endif
                    <button class="mt-1 rounded bg-indigo-600 px-3 py-1 text-white">Try again</button></form>@endif
            @endif
            @if ($d['remote_campaign'])<p class="mt-1 text-xs text-gray-600">Meta ID — campaign {{ $d['remote_campaign'] }}@if ($d['remote_adset']) · Ad set {{ $d['remote_adset'] }}@endif @if ($d['remote_ad']) · Ad {{ $d['remote_ad'] }}@endif</p>@endif
        </div>
        @endif
        @if ($can_manage && $d['status'] === 'draft')
        <form method="POST" action="{{ route('ads.draft.page', $d['id']) }}" class="mt-2 flex flex-wrap items-center gap-2">@csrf
            <label>Facebook page <select name="page_account_id" class="rounded border px-2 py-1"><option value="">— Choose —</option>@foreach ($pages as $pg)<option value="{{ $pg['id'] }}" @selected($d['page_account_id'] === $pg['id'])>{{ $pg['name'] }}</option>@endforeach</select></label>
            <button class="rounded border px-3 py-1">Save</button></form>
        @elseif ($d['page_name'])<p class="mt-2 text-xs text-gray-600">Page: {{ $d['page_name'] }}</p>@endif
        <p class="mt-2 whitespace-pre-line">{{ $d['primary_text'] }}</p>
        <p class="mt-1"><b>{{ $d['headline'] }}</b> — {{ $d['description'] }} [{{ \App\Ads\AdsText::CTAS[$d['cta']] ?? $d['cta'] }}]</p>
        @if ($d['link_url'])<p class="mt-1 text-xs text-gray-600">Link: {{ $d['link_url'] }}</p>@endif
        <p class="mt-2 text-xs text-gray-600">Audience: {{ $d['country'] }}, {{ $d['age_min'] }}–{{ $d['age_max'] }}, {{ \App\Ads\AdsText::GENDERS[$d['gender']] ?? '' }}@if ($d['interests']), Interests: {{ $d['interests'] }}@endif</p>
        <p class="mt-1">Budget: daily <b>{{ number_format((float) $d['daily_budget'], 2) }} {{ $d['currency'] }}</b> × {{ $d['days'] }} days @if ($d['start_date']) · Start {{ $d['start_date'] }}@endif</p>
    </section>
    @endif
</x-layouts.app>
