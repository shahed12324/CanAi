<x-layouts.app title="Google Ads Drafts">
    {{-- JARVIS ONE: page-specific visual identity; existing actions preserved --}}
    <header class="jarvis-module-hero jarvis-accent-violet" data-jarvis-module="gads" aria-label="Google Ads Drafts">
      <div class="jarvis-module-hero__identity"><span class="jarvis-module-hero__glyph" aria-hidden="true">✦</span><div>
        <div class="jarvis-module-hero__eyebrow">JARVIS ONE <span aria-hidden="true">/</span> GADS</div>
        <h1>Google Ads Drafts</h1><p>Manage Google Ads campaigns and creative drafts.</p>
      </div></div>
      <span class="jarvis-module-hero__section">Gads Workspace</span>
    </header>

    @php $locked = $d && $d['status'] !== 'draft'; $editable = $can_manage && ! $locked; $v = fn ($k, $def = '') => old($k, $d[$k] ?? $def);
         $lines = fn ($k, $arr) => old($k, implode("\n", $arr)); @endphp
    <p class="text-sm"><a class="text-indigo-600 hover:underline" href="{{ route('gads.drafts') }}">← Draft list</a></p>
    <h2 class="mt-1 text-xl font-semibold">{{ $d ? $d['name'] : 'New Search draft' }}</h2>
    <p class="mt-1 rounded bg-amber-50 p-3 text-sm text-amber-900">This is only a draft — <b>Google-nothing is sent and no money is spent.</b> AI It only proposes headlines, descriptions and keywords; you decide the budget. Read and verify the AI’s text yourself (remove any wrong prices/claims).</p>
    @if ($errors->any())<p class="mt-2 rounded bg-red-50 p-3 text-sm text-red-700">{{ $errors->first() }}</p>@endif
    @if (session('status'))<p class="mt-2 rounded bg-green-50 p-3 text-sm text-green-700">{{ session('status') }}</p>@endif
    @if (session('error'))<p class="mt-2 rounded bg-red-50 p-3 text-sm text-red-700">{{ session('error') }}</p>@endif

    @if ($d)
    <section class="mt-3 rounded-lg bg-white p-4 text-sm shadow">
        <p>Status: <b>{{ \App\GoogleAds\GadsText::STATUS[$d['status']] }}</b>@if ($d['approver']) · Approval: {{ $d['approver'] }} ({{ $d['approved_at'] }})@endif</p>
        @if ($d['reject_note'])<p class="mt-1 rounded bg-red-50 p-2 text-red-700">Return note: {{ $d['reject_note'] }}</p>@endif
        @if ($d['launch_state'])
        <div class="mt-2 rounded border p-2 {{ $d['launch_state'] === 'failed' ? 'border-red-300 bg-red-50' : 'bg-gray-50' }}">
            @if ($d['launch_state'] === 'created')<p class="text-green-800">Google Ads-was created in paused (PAUSED) state ({{ $d['launched_at'] }}). <b> no money is being spent yet</b> — To start it, go to Google Ads and start the campaign yourself "Enable" do it yourself.</p>
            @elseif ($d['launch_state'] === 'launching')<p>Google Ads-is being created… (in paused state)</p>
            @else<p class="text-red-700">Not created: {{ $d['launch_error'] }}</p>
                @if ($d['launch_unknown'])<p class="mt-1 text-xs text-red-700">Result unknown — first check in Google Ads whether anything was created (look for the [J1-…] code at the end of the name).</p>@endif
                @if ($can_manage && $d['user_id'] === $me)<form method="POST" action="{{ route('gads.draft.retry', $d['id']) }}" class="mt-2">@csrf
                    @if ($d['launch_unknown'])<label class="flex items-center gap-2 text-xs"><input type="checkbox" name="confirm" value="1"> I checked Google Ads; I understand the risk of duplicates if I retry</label>@endif
                    <button class="mt-1 rounded bg-indigo-600 px-3 py-1 text-white">Try again</button></form>@endif
            @endif
            @if ($d['remote_campaign'])<p class="mt-1 text-xs text-gray-600">Google ID — campaign {{ $d['remote_campaign'] }}@if ($d['remote_adgroup']) · Ad group {{ $d['remote_adgroup'] }}@endif @if ($d['remote_ad']) · Ad {{ $d['remote_ad'] }}@endif</p>@endif
        </div>
        @endif
        <div class="mt-2 flex flex-wrap gap-2">
            @if ($can_manage && $d['user_id'] === $me && $d['status'] === 'draft')<form method="POST" action="{{ route('gads.draft.submit', $d['id']) }}">@csrf<button class="rounded bg-indigo-600 px-3 py-1 text-white">Submit for approval</button></form>@endif
            @if ($can_manage && $d['user_id'] === $me && $d['status'] !== 'draft' && (! $d['launch_state'] || ($d['launch_state'] === 'failed' && ! $d['launch_unknown'] && ! $d['remote_budget'] && ! $d['remote_campaign'])))<form method="POST" action="{{ route('gads.draft.reopen', $d['id']) }}">@csrf<button class="rounded border px-3 py-1">Return to draft</button></form>@endif
            @if ($can_manage && $d['user_id'] === $me && $d['status'] === 'draft')<form method="POST" action="{{ route('gads.draft.destroy', $d['id']) }}" onsubmit="return confirm('Delete this draft?')">@csrf<button class="rounded border px-3 py-1 text-red-700">Delete</button></form>@endif
        </div>
        @if ($can_approve && $d['status'] === 'pending')
        <form method="POST" action="{{ route('gads.draft.decide', $d['id']) }}" class="mt-3 grid gap-2 border-t pt-3">@csrf
            <p class="font-semibold">Approval: daily {{ number_format((float) $d['daily_budget'], 2) }} {{ $d['currency'] }} × {{ $d['days'] }} days = total maximum {{ number_format((float) $d['daily_budget'] * $d['days'], 2) }} {{ $d['currency'] }}
                @if ($caps) <span class="font-normal text-gray-500">(Limit: daily {{ number_format((float) $caps['max_daily'], 2) }}, Total {{ number_format((float) $caps['max_total'], 2) }})</span>@endif</p>
            <input name="note" maxlength="300" placeholder="Reason for returning (optional)" class="rounded border px-2 py-1">
            <div class="flex gap-2"><button name="decision" value="approve" class="rounded bg-green-600 px-3 py-1 text-white">Approve</button><button name="decision" value="reject" class="rounded border px-3 py-1">Return</button></div>
        </form>@endif
    </section>
    @endif

    @if ($editable)
    <section class="mt-3 rounded-lg bg-white p-4 text-sm shadow"><h3 class="font-semibold">AI Write with (uses credits)</h3>
        <label class="mt-1 grid gap-1">What is the product/service? (write only true facts — AI will not invent prices or discounts)<textarea id="brief" maxlength="1500" rows="3" class="rounded border px-2 py-1"></textarea></label>
        <div class="mt-2 flex flex-wrap items-center gap-2"><select id="tone" class="rounded border px-2 py-1">@foreach (\App\Social\SocialText::TONES as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select>
            <button type="button" id="ai" class="rounded bg-indigo-600 px-3 py-1 text-white">Suggest copy</button><span id="ai-msg" class="text-xs text-gray-600"></span></div>
    </section>
    <form method="POST" action="{{ $d ? route('gads.draft.update', $d['id']) : route('gads.draft.store') }}" class="mt-3 grid gap-3 rounded-lg bg-white p-4 text-sm shadow">@csrf
        <label class="grid gap-1">Account<select name="account_id" class="rounded border px-2 py-1">@foreach ($accounts as $a)<option value="{{ $a['id'] }}" @selected($v('account_id') === $a['id'])>{{ $a['name'] }} ({{ $a['currency'] }})</option>@endforeach</select></label>
        <label class="grid gap-1">Campaign name<input name="name" id="f-name" maxlength="120" required value="{{ $v('name') }}" class="rounded border px-2 py-1"></label>
        <div class="grid gap-3 sm:grid-cols-3">
            <label class="grid gap-1">Country (2 letters)<input name="country" maxlength="2" value="{{ $v('country', 'BD') }}" class="rounded border px-2 py-1"></label>
            <label class="grid gap-1">Language<select name="language" class="rounded border px-2 py-1">@foreach (\App\GoogleAds\GadsText::LANGUAGES as $k => $l)<option value="{{ $k }}" @selected($v('language', 'bn') === $k)>{{ $l }}</option>@endforeach</select></label>
            <label class="grid gap-1">Landing link (https://…)<input name="final_url" maxlength="500" value="{{ $v('final_url') }}" class="rounded border px-2 py-1"></label>
        </div>
        <label class="grid gap-1">Headlines — one per line, each max 30 characters, 3–15<textarea name="headlines" id="f-headlines" rows="6" class="rounded border px-2 py-1">{{ $lines('headlines', $d['headlines'] ?? []) }}</textarea></label>
        <label class="grid gap-1">Descriptions — one per line, each max 90 characters, 2–4<textarea name="descriptions" id="f-descriptions" rows="3" class="rounded border px-2 py-1">{{ $lines('descriptions', $d['descriptions'] ?? []) }}</textarea></label>
        <label class="grid gap-1">Keywords — one per line (max 30). Plain text = phrase match; <code>[like this]</code> = exact match. Broad match is not used.<textarea name="keywords" id="f-keywords" rows="6" class="rounded border px-2 py-1">{{ old('keywords', \App\GoogleAds\GadsLines::keywordBlock($d['keywords'] ?? [])) }}</textarea></label>
        <label class="grid gap-1">Negative keywords — one per line (ads will not show when these are searched)<textarea name="negatives" id="f-negatives" rows="3" class="rounded border px-2 py-1">{{ $lines('negatives', $d['negatives'] ?? []) }}</textarea></label>
        <fieldset class="grid gap-3 rounded border p-3 sm:grid-cols-3"><legend class="px-1 text-xs text-gray-500">Budget (you decide — not AI)</legend>
            <label class="grid gap-1">Daily budget<input name="daily_budget" inputmode="decimal" required value="{{ $v('daily_budget') }}" class="rounded border px-2 py-1"></label>
            <label class="grid gap-1">How many days (1–60)<input name="days" inputmode="numeric" required value="{{ $v('days', 7) }}" class="rounded border px-2 py-1"></label>
            <label class="grid gap-1">Start date (required for creating in Google)<input type="date" name="start_date" value="{{ $v('start_date') }}" class="rounded border px-2 py-1"></label>
            @if ($caps)<p class="text-xs text-gray-500 sm:col-span-3">Limit: daily {{ number_format((float) $caps['max_daily'], 2) }}, Total {{ number_format((float) $caps['max_total'], 2) }}</p>@else<p class="text-xs text-red-700 sm:col-span-3">Spending limit is not set — the owner/admin must "Draft list" set a limit from, otherwise nothing can be submitted.</p>@endif</fieldset>
        <div><button class="rounded bg-indigo-600 px-3 py-1 text-white">Save</button></div>
    </form>
    <script>
    (function () {
        var $ = function (i) { return document.getElementById(i); }, rid = @json($rid), csrf = document.querySelector('input[name=_token]').value;
        $('ai').addEventListener('click', function () {
            var b = $('ai'), m = $('ai-msg'); if (!$('brief').value.trim()) { m.textContent = 'Write what the product/service is.'; return; }
            b.disabled = true; m.textContent = 'Writing…';
            fetch(@json(route('gads.draft.ai')), {method: 'POST', headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf},
                body: JSON.stringify({rid: rid, brief: $('brief').value, tone: $('tone').value})})
                .then(function (r) { return r.json().then(function (j) { return [r.ok, j]; }); })
                .then(function (x) {
                    if (x[0] && x[1].ok) {
                        var j = x[1];
                        if (j.name && !$('f-name').value) { $('f-name').value = j.name; }
                        $('f-headlines').value = (j.headlines || []).join('\n');
                        $('f-descriptions').value = (j.descriptions || []).join('\n');
                        $('f-keywords').value = (j.keywords || []).map(function (k) { return k.m === 'exact' ? '[' + k.t + ']' : k.t; }).join('\n');
                        $('f-negatives').value = (j.negatives || []).join('\n');
                        m.textContent = 'The proposal has been placed in the form — read, verify and save.';
                        rid = Array.from({length: 26}, function () { return '0123456789abcdefghijklmnopqrstuvwxyz'[Math.floor(Math.random() * 36)]; }).join('');
                    } else { m.textContent = (x[1] && x[1].message) || 'Could not be done.'; }
                }).catch(function () { m.textContent = 'Could not be reached.'; }).then(function () { b.disabled = false; });
        });
    })();
    </script>
    @elseif ($d)
    <section class="mt-3 rounded-lg bg-white p-4 text-sm shadow">
        <p><b>{{ $d['name'] }}</b> · {{ $d['account_name'] }} · {{ $d['country'] }} · {{ \App\GoogleAds\GadsText::LANGUAGES[$d['language']] ?? $d['language'] }}</p>
        @if ($d['final_url'])<p class="mt-1 text-xs text-gray-600">Link: {{ $d['final_url'] }}</p>@endif
        <p class="mt-2 text-xs font-semibold text-gray-600">Title</p>@foreach ($d['headlines'] as $x)<p>{{ $x }}</p>@endforeach
        <p class="mt-2 text-xs font-semibold text-gray-600">Description</p>@foreach ($d['descriptions'] as $x)<p>{{ $x }}</p>@endforeach
        <p class="mt-2 text-xs font-semibold text-gray-600">Keywords</p><pre class="whitespace-pre-wrap">{{ \App\GoogleAds\GadsLines::keywordBlock($d['keywords']) }}</pre>
        @if ($d['negatives'])<p class="mt-2 text-xs font-semibold text-gray-600">Negative</p><p>{{ implode(', ', $d['negatives']) }}</p>@endif
        <p class="mt-2">Budget: daily <b>{{ number_format((float) $d['daily_budget'], 2) }} {{ $d['currency'] }}</b> × {{ $d['days'] }} days @if ($d['start_date']) · Start {{ $d['start_date'] }}@endif</p>
    </section>
    @endif
</x-layouts.app>
