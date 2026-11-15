@php($r = $a['result'])
<x-layouts.app title="SEO Audit">
    {{-- JARVIS ONE: page-specific visual identity; existing actions preserved --}}
    <header class="jarvis-module-hero jarvis-accent-violet" data-jarvis-module="seo" aria-label="SEO Audit">
      <div class="jarvis-module-hero__identity"><span class="jarvis-module-hero__glyph" aria-hidden="true">✦</span><div>
        <div class="jarvis-module-hero__eyebrow">JARVIS ONE <span aria-hidden="true">/</span> SEO</div>
        <h1>SEO Audit</h1><p>Audit websites and track search optimization work.</p>
      </div></div>
      <span class="jarvis-module-hero__section">Seo Workspace</span>
    </header>

    <p class="text-sm"><a class="text-indigo-600" href="{{ route('seo.index') }}">← All audits</a></p>
    <h2 class="mt-1 text-xl font-semibold">{{ $a['domain'] !== '' ? $a['domain'] : $a['url'] }}</h2>
    <p class="text-xs text-gray-500">{{ \Illuminate\Support\Carbon::parse($a['created_at'])->format('Y-m-d H:i') }}@if ($a['client_name']) · Client: {{ $a['client_name'] }}@endif @if ($a['keyword']) · Target keyword: {{ $a['keyword'] }}@endif</p>
    @if (session('status'))<p class="mt-2 rounded bg-green-50 p-3 text-sm text-green-700">{{ session('status') }}</p>@endif
    @if ($errors->any())<p class="mt-2 rounded bg-red-50 p-3 text-sm text-red-700">{{ $errors->first() }}</p>@endif

    @if ($a['status'] !== 'ok')
        <p class="mt-3 rounded bg-red-50 p-3 text-sm text-red-700">{{ \App\Seo\SeoText::message($a['status']) }}</p>
    @else
    <section class="mt-3 flex flex-wrap items-center gap-6 rounded-lg bg-white p-4 text-sm shadow">
        <div><div class="text-4xl font-bold">{{ $a['score'] }}<span class="text-base font-normal text-gray-500">/100</span></div><div class="text-gray-600">{{ \App\Seo\SeoText::grade((int) $a['score']) }}</div>
            @if ($a['previous_score'] !== null)@php($d = (int) $a['score'] - $a['previous_score'])<div class="text-xs {{ $d >= 0 ? 'text-green-700' : 'text-red-700' }}">Compared with the previous audit {{ $d >= 0 ? '+' : '' }}{{ $d }} (First {{ $a['previous_score'] }})</div>@endif</div>
        <ul class="grid gap-1 text-gray-700">
            <li>{{ ($r['site']['https'] ?? false) ? '✅' : '❌' }} HTTPS</li><li>{{ ($r['site']['sitemap'] ?? false) ? '✅' : '❌' }} sitemap.xml @if (($r['site']['sitemap_urls'] ?? 0) > 0)<span class="text-xs text-gray-500">({{ $r['site']['sitemap_urls'] }} addresses)</span>@endif</li><li>{{ ($r['site']['robots_txt'] ?? false) ? '✅' : '❌' }} robots.txt</li>
            <li class="text-xs text-gray-500">{{ $a['pages_checked'] }} pages were checked</li>
        </ul>
    </section>

    <section class="mt-3 grid gap-2">
        @forelse (($r['issues'] ?? []) as $i)
            <div class="rounded-lg bg-white p-3 text-sm shadow">
                <div class="flex flex-wrap items-center gap-2"><span class="rounded px-2 py-0.5 text-xs {{ ['high' => 'bg-red-100 text-red-800', 'medium' => 'bg-amber-100 text-amber-800', 'low' => 'bg-gray-100 text-gray-700'][$i['severity']] ?? 'bg-gray-100 text-gray-700' }}">{{ \App\Seo\SeoText::severity($i['severity']) }}</span><span class="font-semibold">{{ $i['label'] }}</span>@if ($i['count'] > 0)<span class="text-xs text-gray-500">{{ $i['count'] }} on pages</span>@endif</div>
                <p class="mt-1 text-gray-700">{{ $i['advice'] }}</p>
                @if ($i['pages'] !== [])<ul class="mt-1 list-disc pl-5 text-xs text-gray-500">@foreach ($i['pages'] as $u)<li class="break-all">{{ $u }}</li>@endforeach</ul>@endif
            </div>
        @empty
            <p class="rounded bg-green-50 p-3 text-sm text-green-700">No problems found. 🎉</p>
        @endforelse
    </section>

    <section class="mt-4 rounded-lg bg-white p-4 text-sm shadow"><h3 class="font-semibold">By page</h3>
        <table class="mt-2 w-full text-left"><thead><tr class="text-xs text-gray-500"><th>Page</th><th>Status</th><th>Words</th><th>Problem</th></tr></thead><tbody>
        @foreach (($r['pages'] ?? []) as $p)<tr class="border-t align-top"><td class="py-1 break-all">{{ $p['url'] }}@if ($p['title'] !== '')<div class="text-xs text-gray-500">{{ $p['title'] }}</div>@endif</td><td>{{ $p['status'] }}</td><td>{{ $p['words'] }}</td><td>{{ count($p['issues']) }}</td></tr>@endforeach
        </tbody></table>
    </section>
    @endif

    @if ($canManage)
    <form method="POST" action="{{ route('seo.delete', $a['id']) }}" class="mt-4" onsubmit="return confirm('Delete this audit?')">@csrf<button class="rounded border border-red-300 px-3 py-1 text-sm text-red-700">Delete audit</button></form>
    @endif
</x-layouts.app>
