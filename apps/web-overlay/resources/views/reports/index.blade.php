<x-layouts.app title="Client report">
    {{-- JARVIS ONE: page-specific visual identity; existing actions preserved --}}
    <header class="jarvis-module-hero jarvis-accent-violet" data-jarvis-module="reports" aria-label="Client report">
      <div class="jarvis-module-hero__identity"><span class="jarvis-module-hero__glyph" aria-hidden="true">✦</span><div>
        <div class="jarvis-module-hero__eyebrow">JARVIS ONE <span aria-hidden="true">/</span> REPORTS</div>
        <h1>Client report</h1>
      </div></div>
      <span class="jarvis-module-hero__section">Reports Workspace</span>
    </header>

    <h2 class="text-xl font-semibold">Client report</h2>
    <p class="mt-1 rounded bg-amber-50 p-3 text-sm text-amber-900">Meta, Google Ads @if ($social) and social @endif-Weekly or monthly report from copied numbers. No AI needed, no cost; nothing is sent anywhere. Brand kit: <b>{{ $brand ?? 'Not set (default colors)' }}</b> — <a class="text-indigo-600" href="{{ route('brand.show') }}">Change</a>. The weekly report includes comparison with the previous week; monthly (last 30 days) has no comparison because we keep only 30 days of data.</p>
    @if ($errors->any())<p class="mt-2 rounded bg-red-50 p-3 text-sm text-red-700">{{ $errors->first() }}</p>@endif

    <form method="POST" action="{{ route('reports.generate') }}" class="mt-3 grid gap-3 rounded-lg bg-white p-4 text-sm shadow">@csrf
        <div class="flex flex-wrap gap-4">
            <label class="grid gap-1">Period<select name="kind" class="rounded border px-2 py-1"><option value="week" @selected(old('kind', $old_kind ?? 'week') === 'week')>Last 7 days (weekly)</option><option value="month" @selected(old('kind', $old_kind ?? '') === 'month')>Last 30 days (monthly)</option></select></label>
            <label class="grid gap-1">Language<select name="language" class="rounded border px-2 py-1"><option value="bn" @selected(old('language', 'bn') === 'bn')>Bengali</option><option value="en" @selected(old('language') === 'en')>English</option></select></label>
        </div>
        @if (! empty($clients))<label class="grid gap-1">Report for which client<select name="client_id" class="rounded border px-2 py-1"><option value="">All accounts (workspace’s own)</option>@foreach ($clients as $c)<option value="{{ $c['id'] }}" @selected(old('client_id') === $c['id'])>{{ $c['name'] }}</option>@endforeach</select><span class="text-xs text-gray-500">If you pick a client, only the numbers of its linked accounts/pages and its brand appear — <a class="text-indigo-600" href="{{ route('clients.index') }}">Manage clients</a>.</span></label>@endif
        <label class="grid gap-1">Client name (optional; if a client is picked and this is left blank, its name is used)<input name="client_name" maxlength="200" value="{{ old('client_name') }}" class="rounded border px-2 py-1"></label>
        <label class="grid gap-1">What we did in this period (optional — for example text, "- " start with to make a list; what you write goes into the report exactly)<textarea name="notes" rows="5" maxlength="3000" class="rounded border px-2 py-1">{{ old('notes') }}</textarea></label>
        <div class="flex flex-wrap gap-2"><button name="action" value="preview" class="rounded border px-3 py-1">Preview</button><button name="action" value="pdf" class="rounded bg-indigo-600 px-3 py-1 text-white">PDF Download</button><button name="action" value="docx" class="rounded bg-indigo-600 px-3 py-1 text-white">DOCX Download</button></div>
    </form>

    @if ($doc)
    <section class="mt-3 rounded-lg bg-white p-5 text-sm shadow">
        <h3 class="text-lg font-semibold" style="color: {{ $doc['brand']['primary'] }}">{{ $doc['title'] }}</h3>
        <p class="text-xs text-gray-500">@if ($doc['client_name']){{ $doc['client_name'] }} · @endif{{ $doc['date_text'] }}@if ($doc['brand']['name']) · {{ $doc['brand']['name'] }}@endif</p>
        @foreach ($doc['sections'] as $s)
            <h4 class="mt-4 font-semibold" style="color: {{ $doc['brand']['primary'] }}">{{ $s['heading'] }}</h4>
            <div class="whitespace-pre-line text-gray-800">{{ $s['body'] }}</div>
        @endforeach
        @if (! empty($doc['pricing']))
        <h4 class="mt-4 font-semibold" style="color: {{ $doc['brand']['primary'] }}">{{ $doc['labels']['pricing'] }}</h4>
        <div class="overflow-x-auto"><table class="mt-1 w-full text-left"><thead><tr class="text-xs text-gray-500"><th>{{ $doc['labels']['item'] }}</th><th>{{ $doc['labels']['qty'] }}</th><th>{{ $doc['labels']['unit_price'] }}</th><th>{{ $doc['labels']['amount'] }}</th></tr></thead><tbody>
            @foreach ($doc['pricing']['rows'] as $r)<tr class="border-t"><td class="py-1">{{ $r['name'] }}</td><td>{{ $r['qty'] }}</td><td>{{ $r['unit_price'] }}</td><td>{{ $r['total'] }}</td></tr>@endforeach
        </tbody></table></div>
        @if ($doc['pricing']['note'])<p class="mt-1 text-xs text-gray-500">{{ $doc['pricing']['note'] }}</p>@endif
        @endif
    </section>
    @endif
</x-layouts.app>
