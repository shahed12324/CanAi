<x-layouts.app title="Connections">
    {{-- JARVIS ONE: page-specific visual identity; existing actions preserved --}}
    <header class="jarvis-module-hero jarvis-accent-violet" data-jarvis-module="connect" aria-label="Connections">
      <div class="jarvis-module-hero__identity"><span class="jarvis-module-hero__glyph" aria-hidden="true">✦</span><div>
        <div class="jarvis-module-hero__eyebrow">JARVIS ONE <span aria-hidden="true">/</span> CONNECT</div>
        <h1>Connections</h1>
      </div></div>
      <span class="jarvis-module-hero__section">Connect Workspace</span>
    </header>

    <h2 class="text-xl font-semibold">Connections — Calendar, Slack, Discord</h2>
    <p class="mt-1 rounded bg-amber-50 p-3 text-sm text-amber-800">What you add here is <b> only your own</b>. A secret-link (ICS) calendar is <b> read only</b>. For Google/Outlook OAuth calendars, Jarvis <b> only on your click or after your approval</b> adds one event at a time — it never changes or deletes earlier events and never sends invitations. Webhook addresses and calendar secret links are stored encrypted and are not shown again; do not share them with anyone. If an agent (workflow) wants to send a message to a channel, it will ask for your approval first.</p>
    @if ($errors->any())<p class="mt-2 rounded bg-red-50 p-3 text-sm text-red-700">{{ $errors->first() }}</p>@endif
    @if (session('status'))<p class="mt-2 rounded bg-green-50 p-3 text-sm text-green-700">{{ session('status') }}</p>@endif

    <section class="mt-3 rounded-lg bg-white p-4 text-sm shadow"><h3 class="font-semibold">Events in the next 7 days</h3>
        @if ($up['feeds'] === 0)<p class="mt-1 text-gray-500">No calendar added — add one below.</p>
        @else
            @foreach ($up['errors'] as $e)<p class="mt-1 rounded bg-red-50 p-2 text-xs text-red-700">{{ $e }}</p>@endforeach
            <ul class="mt-2 space-y-1">@forelse ($up['events'] as $e)
                <li class="rounded border p-2"><b>{{ $e['summary'] }}</b> <span class="text-xs text-gray-600">· {{ $e['all_day'] ? substr($e['start'], 0, 10).' (All day)' : str_replace('T', ' ', $e['start']) }}{{ $e['location'] !== '' ? ' · '.$e['location'] : '' }} · {{ $e['calendar'] }}</span></li>
                @empty<li class="text-gray-500">No events in this period.</li>@endforelse</ul>
            <p class="mt-1 text-xs text-gray-500">A new event can take up to 10 minutes to show.</p>@endif
    </section>

    <section class="mt-3 rounded-lg bg-white p-4 text-sm shadow"><h3 class="font-semibold">Calendar (read-only)</h3>
        <p class="mt-1 text-xs text-gray-600"><b>Google Calendar:</b> Settings → your calendar → "Integrate calendar" → <i>Secret address in iCal format</i> copy the link. <b>Outlook:</b> Settings → Calendar → Shared calendars → Publish a calendar → ICS link. The link will start with https:// (or webcal://).</p>
        @foreach ($feeds as $f)
        <div class="mt-2 flex flex-wrap items-center justify-between gap-2 rounded border p-2"><span><b>{{ $f['name'] }}</b> <span class="text-xs text-gray-500">· {{ $f['host'] }}{{ $f['last_error'] ? ' · ⚠ '.$f['last_error'] : '' }}</span></span>
            <form method="POST" action="{{ route('connect.destroy', $f['id']) }}" onsubmit="return confirm('Remove?')">@csrf<button class="text-xs text-red-700 underline">Remove</button></form></div>@endforeach
        <form method="POST" action="{{ route('connect.calendar') }}" class="mt-2 grid gap-2" onsubmit="this.querySelector('button').disabled = true">@csrf
            <input name="name" maxlength="60" required placeholder="Name (for example: Office)" value="{{ old('name') }}" class="rounded border px-2 py-1">
            <input name="url" maxlength="700" required placeholder="https://calendar.google.com/calendar/ical/…/basic.ics" autocomplete="off" class="rounded border px-2 py-1">
            <div><button class="rounded bg-indigo-600 px-3 py-1 text-white">Add calendar</button></div></form>
    </section>

    <section class="mt-3 rounded-lg bg-white p-4 text-sm shadow"><h3 class="font-semibold">Google / Outlook Calendar (OAuth — read and add events)</h3>
        <p class="mt-1 text-xs text-gray-600">When you grant permission your events show here and you can add one event at a time if you wish. No guest invitations are sent; no earlier event is changed or deleted. You can disconnect anytime using the one below "Remove" to disconnect.</p>
        @foreach ($oauth as $a)
        <div class="mt-2 flex flex-wrap items-center justify-between gap-2 rounded border p-2"><span><b>{{ $a['name'] }}</b> <span class="text-xs text-gray-500">· {{ $a['host'] === 'google' ? 'Google' : 'Outlook' }}{{ $a['last_error'] ? ' · ⚠ '.\App\Calendar\CalendarAccounts::message($a['last_error']) : '' }}</span></span>
            <form method="POST" action="{{ route('connect.destroy', $a['id']) }}" onsubmit="return confirm('Remove the connection?')">@csrf<button class="text-xs text-red-700 underline">Remove</button></form></div>
        @endforeach
        <div class="mt-2 flex flex-wrap gap-2">
            @foreach (['google' => 'Google Connect calendar', 'microsoft' => 'Outlook Connect calendar'] as $prov => $label)
                @if ($oauthReady[$prov])
                <form method="POST" action="{{ route('connect.oauth.start', $prov) }}">@csrf<button class="rounded bg-indigo-600 px-3 py-1 text-white">{{ $label }}</button></form>
                @else
                <span class="rounded bg-gray-100 px-3 py-1 text-xs text-gray-500">{{ $label }} (OAuth key is not set on the server)</span>
                @endif
            @endforeach
        </div>
        @if (count($oauth) > 0)
        <form method="POST" action="{{ route('connect.event') }}" class="mt-3 grid gap-2 border-t pt-3" onsubmit="this.querySelector('button').disabled = true">@csrf
            <h4 class="font-medium">Add a new event</h4>
            <input name="title" maxlength="200" required placeholder="Event name" value="{{ old('title') }}" class="rounded border px-2 py-1">
            <div class="flex flex-wrap gap-2">
                <input type="datetime-local" name="start" required value="{{ old('start') }}" class="rounded border px-2 py-1">
                <input type="number" name="minutes" min="5" max="1440" required value="{{ old('minutes', 60) }}" class="w-24 rounded border px-2 py-1"> <span class="self-center text-xs text-gray-600">Minutes</span>
                <select name="calendar" class="rounded border px-2 py-1">@foreach ($oauth as $a)<option value="{{ $a['id'] }}">{{ $a['name'] }}</option>@endforeach</select>
            </div>
            <input name="location" maxlength="200" placeholder="Location (optional)" value="{{ old('location') }}" class="rounded border px-2 py-1">
            <div><button class="rounded bg-indigo-600 px-3 py-1 text-white">Add to calendar</button></div></form>
        @endif
    </section>

    <section class="mt-3 rounded-lg bg-white p-4 text-sm shadow"><h3 class="font-semibold">Slack and Discord (webhook for sending messages)</h3>
        <p class="mt-1 text-xs text-gray-600"><b>Slack:</b> api.slack.com → Incoming Webhooks → Pick a channel and get the address. <b>Discord:</b> Channel settings → Integrations → Webhooks → Copy Webhook URL. Only these two services’ own addresses are accepted. @everyone/@here/Individual mentions are automatically disabled when sending.</p>
        @foreach (['slack' => ['Slack', $slack], 'discord' => ['Discord', $discord]] as $k => [$label, $list])
            @foreach ($list as $h)
            <div class="mt-2 flex flex-wrap items-center justify-between gap-2 rounded border p-2"><span><b>{{ $h['name'] }}</b> <span class="text-xs text-gray-500">· {{ $label }}{{ $h['last_error'] ? ' · ⚠ Last failed ('.$h['last_error'].')' : '' }}</span></span>
                <span class="flex gap-3"><form method="POST" action="{{ route('connect.test', $h['id']) }}">@csrf<button class="text-xs text-indigo-700 underline">Test message</button></form>
                <form method="POST" action="{{ route('connect.destroy', $h['id']) }}" onsubmit="return confirm('Remove?')">@csrf<button class="text-xs text-red-700 underline">Remove</button></form></span></div>@endforeach
        @endforeach
        <form method="POST" action="{{ route('connect.hook') }}" class="mt-2 grid gap-2" onsubmit="this.querySelector('button').disabled = true">@csrf
            <div class="flex gap-2"><select name="kind" class="rounded border px-2 py-1"><option value="slack" @selected(old('kind') === 'slack')>Slack</option><option value="discord" @selected(old('kind') === 'discord')>Discord</option></select>
                <input name="name" maxlength="60" required placeholder="Name (for example: Team channel)" value="{{ old('name') }}" class="flex-1 rounded border px-2 py-1"></div>
            <input name="url" maxlength="400" required placeholder="Webhook address" autocomplete="off" class="rounded border px-2 py-1">
            <div><button class="rounded bg-indigo-600 px-3 py-1 text-white">Add</button></div></form>
    </section>
</x-layouts.app>
