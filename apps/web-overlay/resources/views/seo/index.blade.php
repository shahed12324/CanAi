<x-layouts.app title="SEO Audit">
    {{-- JARVIS ONE: page-specific visual identity; existing actions preserved --}}
    <header class="jarvis-module-hero jarvis-accent-violet" data-jarvis-module="seo" aria-label="SEO Audit">
      <div class="jarvis-module-hero__identity"><span class="jarvis-module-hero__glyph" aria-hidden="true">✦</span><div>
        <div class="jarvis-module-hero__eyebrow">JARVIS ONE <span aria-hidden="true">/</span> SEO</div>
        <h1>SEO Audit</h1><p>Audit websites and track search optimization work.</p>
      </div></div>
      <span class="jarvis-module-hero__section">Seo Workspace</span>
    </header>

    <h2 class="text-xl font-semibold">SEO Audit</h2>
    <p class="mt-1 rounded bg-amber-50 p-3 text-sm text-amber-900">Checks basic website SEO (title, meta description, headings, image alt, mobile-friendliness, sitemap, HTTPS and so on) and tells you the fix for each problem. This is a rule-based check: no AI needed, no credits spent, and nothing on the site is changed. The site’s robots.txt is obeyed; maximum 30 audits per 24 hours.</p>
    @if (session('status'))<p class="mt-2 rounded bg-green-50 p-3 text-sm text-green-700">{{ session('status') }}</p>@endif
    @if ($errors->any())<p class="mt-2 rounded bg-red-50 p-3 text-sm text-red-700">{{ $errors->first() }}</p>@endif

    @if ($canManage)
    <section class="mt-4 rounded-lg bg-white p-4 text-sm shadow"><h3 class="font-semibold">New audit</h3>
        <form method="POST" action="{{ route('seo.run') }}" class="mt-2 grid gap-2 sm:grid-cols-2">@csrf
            <label class="grid gap-1 sm:col-span-2">Website<input name="url" value="{{ old('url') }}" required maxlength="2048" placeholder="example.com" class="rounded border px-2 py-1"></label>
            <label class="grid gap-1">Target keyword (optional, for homepage check)<input name="keyword" value="{{ old('keyword') }}" maxlength="80" placeholder="For example: digital marketing in Dhaka" class="rounded border px-2 py-1"></label>
            <label class="grid gap-1">How many pages to check<select name="max_pages" class="rounded border px-2 py-1">@foreach ([1, 3, 5, 8] as $n)<option value="{{ $n }}" @selected((int) old('max_pages', 5) === $n)>{{ $n }}</option>@endforeach</select></label>
            @if ($clients !== [])<label class="grid gap-1">Client (optional)<select name="client_id" class="rounded border px-2 py-1"><option value="">— Own —</option>@foreach ($clients as $c)<option value="{{ $c['id'] }}" @selected(old('client_id') === $c['id'])>{{ $c['name'] }}</option>@endforeach</select></label>@endif
            <div class="sm:col-span-2"><button class="rounded bg-indigo-600 px-3 py-1 text-white">Run audit</button> <span class="text-xs text-gray-500">Can take up to 1 minute.</span></div>
        </form>
    </section>
    @endif

    <section class="mt-4 rounded-lg bg-white p-4 text-sm shadow"><h3 class="font-semibold">Previous audits</h3>
        <table class="mt-2 w-full text-left"><thead><tr class="text-xs text-gray-500"><th>Site</th><th>Client</th><th>Score</th><th>Date</th><th></th></tr></thead><tbody>
        @forelse ($audits as $a)
            <tr class="border-t"><td class="py-1">{{ $a['domain'] !== '' ? $a['domain'] : $a['url'] }}@if ($a['keyword'])<span class="text-xs text-gray-500"> · {{ $a['keyword'] }}</span>@endif</td><td>{{ $a['client_name'] ?? '—' }}</td>
                <td>@if ($a['score'] !== null)<span class="font-semibold">{{ $a['score'] }}</span>/100 <span class="text-xs text-gray-500">{{ \App\Seo\SeoText::grade($a['score']) }}</span>@else<span class="text-xs text-red-600">{{ \App\Seo\SeoText::message($a['status']) }}</span>@endif</td>
                <td class="text-xs text-gray-500">{{ \Illuminate\Support\Carbon::parse($a['created_at'])->format('Y-m-d H:i') }}</td><td><a class="text-indigo-600" href="{{ route('seo.show', $a['id']) }}">View</a></td></tr>
        @empty<tr><td colspan="5" class="py-2 text-gray-500">No audits yet.</td></tr>@endforelse
        </tbody></table>
    </section>
</x-layouts.app>
