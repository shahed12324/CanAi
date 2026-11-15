<x-layouts.app title="{{ $p['title'] }}">
    {{-- JARVIS ONE: page-specific visual identity; existing actions preserved --}}
    <header class="jarvis-module-hero jarvis-accent-violet" data-jarvis-module="proposals" aria-label="Edit Proposals">
      <div class="jarvis-module-hero__identity"><span class="jarvis-module-hero__glyph" aria-hidden="true">✦</span><div>
        <div class="jarvis-module-hero__eyebrow">JARVIS ONE <span aria-hidden="true">/</span> PROPOSALS</div>
        <h1>Edit Proposals</h1>
      </div></div>
      <span class="jarvis-module-hero__section">Proposals Workspace</span>
    </header>

    <div class="flex flex-wrap items-center justify-between gap-2"><h2 class="text-xl font-semibold">{{ $p['title'] }}</h2><a class="text-sm text-indigo-600" href="{{ route('proposals.index') }}">← All proposals</a></div>
    @if (session('status'))<p class="rounded bg-green-50 p-3 text-sm text-green-700">{{ session('status') }}</p>@endif
    @if ($errors->any())<div class="rounded bg-red-50 p-3 text-sm text-red-700">@foreach ($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div>@endif

    <section class="flex flex-wrap items-center gap-3 rounded-lg bg-white p-4 text-sm shadow">
        <span>Status: <strong>{{ $statusBn[$p['status']] ?? $p['status'] }}</strong></span>
        @if ($lead)<a class="text-indigo-600" href="{{ route('leads.show', trim($lead->id)) }}">Lead: {{ $lead->name }}</a>@endif
        <span class="ml-auto flex flex-wrap gap-2">
            <a class="rounded bg-indigo-600 px-3 py-1 text-white" href="{{ route('proposals.download', [$p['id'], 'pdf']) }}">PDF Download</a>
            <a class="rounded border px-3 py-1" href="{{ route('proposals.download', [$p['id'], 'docx']) }}">DOCX Download</a>
        </span>
        @if ($canManage)
            <form method="POST" action="{{ route('proposals.status', $p['id']) }}" class="flex items-center gap-1 basis-full">@csrf
                <select name="status" class="rounded border-gray-300 text-sm">@foreach ($statusBn as $k => $l)<option value="{{ $k }}" @selected($p['status'] === $k)>{{ $l }}</option>@endforeach</select>
                <x-button variant="ghost">Change status</x-button>
            </form>
        @endif
    </section>

    <form method="POST" action="{{ route('proposals.update', $p['id']) }}" class="space-y-4">@csrf
        <section class="space-y-3 rounded-lg bg-white p-5 shadow">
            <x-field name="title" label="Title" :value="old('title', $p['title'])" />
            <x-field name="client_name" label="Client name" :value="old('client_name', $p['data']['client_name'] ?? '')" />
            <label class="block text-sm">Language <select name="language" class="rounded border-gray-300"><option value="bn" @selected(old('language', $p['language']) === 'bn')>Bengali</option><option value="en" @selected(old('language', $p['language']) === 'en')>English</option></select></label>
        </section>

        <section class="space-y-3 rounded-lg bg-white p-5 shadow">
            <h3 class="font-medium">Text (sections)</h3>
            <p class="text-xs text-gray-500">Empty line = new paragraph; <code>- </code> a line starting with = bullet. If both heading and text are left blank, the section is dropped.</p>
            @foreach (old('sections', $sections) as $i => $s)
                <div class="space-y-1 border-t pt-2">
                    <input name="sections[{{ $i }}][heading]" value="{{ $s['heading'] ?? '' }}" maxlength="160" placeholder="Section title" class="w-full rounded border-gray-300 text-sm font-medium">
                    <textarea name="sections[{{ $i }}][body]" rows="5" maxlength="8000" class="w-full rounded border-gray-300 text-sm">{{ $s['body'] ?? '' }}</textarea>
                </div>
            @endforeach
        </section>

        <section class="space-y-3 rounded-lg bg-white p-5 shadow">
            <h3 class="font-medium">Value</h3>
            <p class="text-xs text-gray-500">One per line: <code>Name | Quantity | Unit price</code> (If quantity is not given, 1). For example: <code>Website design | 1 | 50,000</code></p>
            <textarea name="pricing_text" rows="6" class="w-full rounded border-gray-300 font-mono text-sm">{{ old('pricing_text', $pricingText) }}</textarea>
            <div class="flex flex-wrap gap-4 text-sm">
                <label>Discount % <input name="discount" value="{{ old('discount', $discount) }}" size="5" class="rounded border-gray-300"></label>
                <label>VAT % <input name="vat" value="{{ old('vat', $vat) }}" size="5" class="rounded border-gray-300"></label>
            </div>
            <div><label class="block text-sm">Note below the price</label><input name="note" value="{{ old('note', $note) }}" maxlength="1000" class="w-full rounded border-gray-300 text-sm"></div>
            @if ($calc['rows'])
                @php($lang = $p['language'])
                <table class="w-full text-sm"><tbody>
                    @foreach ($calc['rows'] as $r)<tr class="border-t"><td>{{ $r['name'] }}</td><td class="text-right">{{ $r['qty'] }} × {{ \App\Proposals\Money::format($r['unit_minor'], $lang) }}</td><td class="text-right">{{ \App\Proposals\Money::format($r['total_minor'], $lang) }}</td></tr>@endforeach
                    <tr class="border-t font-semibold"><td colspan="2" class="text-right">Grand total (including discount and VAT)</td><td class="text-right">{{ \App\Proposals\Money::format($calc['total'], $lang) }}</td></tr>
                </tbody></table>
                <p class="text-xs text-gray-500">This calculation updates when you save.</p>
            @endif
        </section>

        <section class="space-y-2 rounded-lg bg-white p-5 shadow">
            <h3 class="font-medium">Works to show in the proposal</h3>
            @forelse ($portfolio as $w)
                <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="portfolio_ids[]" value="{{ trim($w->id) }}" @checked(in_array(trim($w->id), $picked, true))> {{ $w->title }}</label>
            @empty<p class="text-sm text-gray-500">Nothing in the portfolio. <a class="text-indigo-600" href="{{ route('portfolio.index') }}">Add work</a>.</p>@endforelse
        </section>

        @if ($canManage)<x-button>Save</x-button>@endif
    </form>
</x-layouts.app>
