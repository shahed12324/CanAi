<x-layouts.app title="Developer API">
    {{-- JARVIS ONE: page-specific visual identity; existing actions preserved --}}
    <header class="jarvis-module-hero jarvis-accent-violet" data-jarvis-module="developer" aria-label="Developer API">
      <div class="jarvis-module-hero__identity"><span class="jarvis-module-hero__glyph" aria-hidden="true">✦</span><div>
        <div class="jarvis-module-hero__eyebrow">JARVIS ONE <span aria-hidden="true">/</span> DEVELOPER</div>
        <h1>Developer API</h1>
      </div></div>
      <span class="jarvis-module-hero__section">Developer Workspace</span>
    </header>

    <h2 class="text-xl font-semibold">Developer API</h2>
    <p class="mt-1 text-sm text-gray-600">Create an API key to use CANAI from your own server, n8n or another app. A key works only in your name, in this workspace, within the permissions you choose. A key cannot approve, delete or move money.</p>
    @if ($errors->any())<p class="mt-2 rounded bg-red-50 p-3 text-sm text-red-700">{{ $errors->first() }}</p>@endif
    @if (session('status'))<p class="mt-2 rounded bg-green-50 p-3 text-sm text-green-700">{{ session('status') }}</p>@endif
    @if ($new)<div class="mt-2 rounded border-2 border-indigo-400 bg-indigo-50 p-3 text-sm"><b>Your key (shown only now):</b>
        <input readonly value="{{ $new }}" onfocus="this.select()" class="mt-1 w-full rounded border bg-white px-2 py-1 font-mono text-xs"></div>@endif

    <section class="mt-3 rounded-lg bg-white p-4 text-sm shadow"><h3 class="font-semibold">New key</h3>
        <form method="POST" action="{{ route('developer.store') }}" class="mt-2 grid gap-2">@csrf
            <label class="grid gap-1">Name<input name="name" maxlength="80" required value="{{ old('name') }}" placeholder="n8n Server" class="rounded border px-2 py-1"></label>
            <fieldset>@foreach ($scopes as $k => $label)<label class="flex items-center gap-1"><input type="checkbox" name="scopes[]" value="{{ $k }}" @checked(in_array($k, old('scopes', ['me']), true))> <code class="text-xs">{{ $k }}</code> — {{ $label }}</label>@endforeach</fieldset>
            <label class="grid gap-1">Validity (days, empty = no expiry)<input name="days" type="number" min="1" max="365" value="{{ old('days') }}" class="w-28 rounded border px-2 py-1"></label>
            <div><button class="rounded bg-indigo-600 px-3 py-1 text-white">Create key</button></div></form>
    </section>

    <section class="mt-3 rounded-lg bg-white p-4 text-sm shadow"><h3 class="font-semibold">Your keys ({{ count($keys) }})</h3>
        @forelse ($keys as $k)
        <div class="mt-2 flex flex-wrap items-center justify-between gap-2 rounded border p-2"><span><b>{{ $k['name'] }}</b> · <code class="text-xs">{{ $k['prefix'] }}…</code> · {{ implode(', ', $k['scopes']) }}
            <br><span class="text-xs text-gray-500">Created {{ $k['created_at'] }}@if ($k['last_used_at']) · Last used {{ $k['last_used_at'] }}@endif @if (isset($usage[$k['id']])) · Calls: in 7 days {{ $usage[$k['id']]['last7'] }}, Total {{ $usage[$k['id']]['total'] }} (up to 90 days)@endif @if ($k['expires_at']) · Expiry {{ $k['expires_at'] }}@endif</span>
            @if ($k['revoked_at'])<span class="text-xs text-red-700"> · Closed</span>@endif</span>
            @if (! $k['revoked_at'])<form method="POST" action="{{ route('developer.revoke', $k['id']) }}" onsubmit="return confirm('If you revoke this key nothing will work with it anymore. Sure?')">@csrf<button class="text-xs text-red-700 underline">Deactivate</button></form>@endif
        </div>
        @empty<p class="mt-2 text-xs text-gray-500">No keys yet.</p>@endforelse
    </section>

    <section class="mt-3 rounded-lg bg-white p-4 text-sm shadow"><h3 class="font-semibold">Webhook (runs and computer-action news)</h3>
        <p class="mt-1 text-xs text-gray-600">When any of your workflow runs finish (success, failure or cancelled), a small signed JSON is sent to this address. Optionally you can choose two more events per address: run awaiting approval and computer action awaiting approval. The first three messages contain only the ID, status and type — no file paths, parameters, text or customer data. The fourth (workflow.message) is sent only when your own workflow, after approval, in a step sends text; it carries just that text (max 1000 characters), at most 20 per hour. The address `webhook.send` must start with and be a real domain. On failure it is retried (after 1, 5, 15, 60, 240 minutes); after 10 consecutive failures the address is disabled. <code>https://</code> must start with and be a real domain. On failure it is retried (after 1, 5, 15, 60, 240 minutes); after 10 consecutive failures the address is disabled.</p>
        @if ($errors->has('hook'))<p class="mt-2 rounded bg-red-50 p-3 text-xs text-red-700">{{ $errors->first('hook') }}</p>@endif
        @if ($newSecret)<div class="mt-2 rounded border-2 border-indigo-400 bg-indigo-50 p-3"><b>Signing secret (shown only now):</b>
            <input readonly value="{{ $newSecret }}" onfocus="this.select()" class="mt-1 w-full rounded border bg-white px-2 py-1 font-mono text-xs"></div>@endif
        <form method="POST" action="{{ route('developer.hook.store') }}" class="mt-2 flex flex-wrap items-end gap-2">@csrf
            <label class="grid text-xs">Name<input name="name" maxlength="80" required value="{{ old('name') }}" class="rounded border px-2 py-1 text-sm"></label>
            <label class="grid flex-1 text-xs">Address<input name="url" maxlength="500" required value="{{ old('url') }}" placeholder="https://example.com/jarvis-hook" class="rounded border px-2 py-1 text-sm"></label>
            <button class="rounded bg-indigo-600 px-3 py-1 text-white">Add</button></form>
        @foreach ($hooks as $h)
        <div class="mt-2 flex flex-wrap items-center justify-between gap-2 rounded border p-2"><span><b>{{ $h['name'] }}</b> · <code class="break-all text-xs">{{ $h['url'] }}</code>
            <br><span class="text-xs {{ $h['active'] ? 'text-green-700' : 'text-red-700' }}">{{ $h['active'] ? 'Active' : 'Closed' }}</span>@if ($h['failures'] > 0)<span class="text-xs text-amber-700"> · Consecutive failures {{ $h['failures'] }}@if ($h['last_error']) ({{ $h['last_error'] }})@endif</span>@endif @if ($h['last_ok_at'])<span class="text-xs text-gray-500"> · Last success {{ $h['last_ok_at'] }}</span>@endif</span>
            <form method="POST" action="{{ route('developer.hook.events', $h['id']) }}" class="flex w-full flex-wrap items-center gap-3 text-xs">@csrf
                @foreach ([['run.finished', 'Run finished'], ['run.waiting_approval', 'Run awaiting approval'], ['pc.action.pending', 'Computer action awaiting approval'], ['workflow.message', 'Message sent by workflow (with text)']] as [$ev, $label])
                <label class="flex items-center gap-1"><input type="checkbox" name="events[]" value="{{ $ev }}" @checked(in_array($ev, $h['events'], true))>{{ $label }}</label>
                @endforeach
                <button class="text-indigo-700 underline">Save events</button></form>
            <span class="flex gap-3"><form method="POST" action="{{ route('developer.hook.toggle', $h['id']) }}">@csrf<input type="hidden" name="active" value="{{ $h['active'] ? 0 : 1 }}"><button class="text-xs text-indigo-700 underline">{{ $h['active'] ? 'Deactivate' : 'Activate' }}</button></form>
            <form method="POST" action="{{ route('developer.hook.delete', $h['id']) }}" onsubmit="return confirm('Delete the address?')">@csrf<button class="text-xs text-red-700 underline">Delete</button></form></span></div>
        @endforeach
        @if (count($deliveries) > 0)<h4 class="mt-3 font-semibold">Recent messages</h4>
        @foreach ($deliveries as $d)
        <div class="mt-1 text-xs">{{ $d['created_at'] }} · <b>{{ $d['endpoint'] }}</b> · <code>{{ $d['event'] }}</code> <code>{{ $d['run_id'] }}</code> ·
            @switch($d['status'])@case('sent')<span class="text-green-700">✔ Delivered ({{ $d['last_code'] }})</span>@break @case('dead')<span class="text-red-700">✘ Dropped ({{ $d['attempts'] }} attempts @if ($d['last_code']), End {{ $d['last_code'] }}@endif)</span>@break @default<span class="text-amber-700">… Pending ({{ $d['attempts'] }} attempts)</span>@endswitch</div>
        @endforeach @endif
        <p class="mt-2 text-xs text-gray-600">Verify: header <code>X-Jarvis-Timestamp</code> and <code>X-Jarvis-Signature: v1=…</code> — value is <code>HMAC-SHA256(secret, "timestamp.body")</code>. Reject timestamps a few minutes old. The same message (<code>X-Jarvis-Delivery</code>) can arrive twice — match by ID.</p>
    </section>

    <section class="mt-3 rounded-lg bg-white p-4 text-sm shadow"><h3 class="font-semibold">How to use</h3>
        <p class="mt-1 text-xs text-gray-600">In every request send the header: <code>Authorization: Bearer jk_live_…</code>. The reply is JSON. 60 requests per minute per key.</p>
        <pre class="mt-2 overflow-x-auto rounded bg-gray-900 p-3 text-xs text-gray-100">GET  {{ $base }}/me                      (scope: me)
GET  {{ $base }}/credits                 (scope: credits:read)
GET  {{ $base }}/workflows               (scope: workflows:read)
GET  {{ $base }}/runs/{run_id}           (scope: workflows:read)
POST {{ $base }}/workflows/{id}/run      (scope: workflows:run)
     {"inputs": {"Name": "Value"}}  →  202 {"data": {"run_id": "…", "status": "queued"}}</pre>
        <p class="mt-2 text-xs text-gray-600">While a workflow runs, in steps that send things outside (for example email, writing to a calendar) your approval is still needed as before.</p>
    </section>
</x-layouts.app>
