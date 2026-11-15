<x-layouts.app title="Product">
    {{-- JARVIS ONE: page-specific visual identity; existing actions preserved --}}
    <header class="jarvis-module-hero jarvis-accent-violet" data-jarvis-module="catalog" aria-label="Product">
      <div class="jarvis-module-hero__identity"><span class="jarvis-module-hero__glyph" aria-hidden="true">✦</span><div>
        <div class="jarvis-module-hero__eyebrow">JARVIS ONE <span aria-hidden="true">/</span> CATALOG</div>
        <h1>Product</h1>
      </div></div>
      <span class="jarvis-module-hero__section">Catalog Workspace</span>
    </header>

    <div class="flex flex-wrap items-center justify-between gap-2"><h2 class="text-xl font-semibold">Product catalog</h2>
        @if ($canManage)<div class="flex gap-3 text-sm"><a class="text-indigo-600" href="{{ route('catalog.stores') }}">Store connection</a><a class="text-indigo-600" href="{{ route('catalog.import') }}">CSV Import</a><a class="rounded bg-indigo-600 px-3 py-1 text-white" href="{{ route('catalog.create') }}">New product</a></div>@endif</div>
    @if (session('status'))<p class="rounded bg-green-50 p-3 text-sm text-green-700">{{ session('status') }}</p>@endif
    <form method="GET" class="flex flex-wrap items-center gap-2 text-sm">
        <input name="q" value="{{ $q }}" placeholder="Search by name or SKU" class="rounded border px-2 py-1">
        <select name="status" class="rounded border px-2 py-1"><option value="">All statuses</option>@foreach ($statusBn as $k => $v)<option value="{{ $k }}" @selected($status === $k)>{{ $v }}</option>@endforeach</select>
        <button class="rounded bg-gray-800 px-3 py-1 text-white">Search</button>
    </form>
    <section class="rounded-lg bg-white p-5 shadow">
        @forelse ($rows as $r)
            <a href="{{ route('catalog.edit', trim($r['id'])) }}" class="flex flex-wrap items-center justify-between gap-2 border-t py-2 text-sm first:border-0 hover:bg-gray-50">
                <span><strong>{{ $r['title'] }}</strong>@if ($r['sku']) <span class="text-gray-500">· {{ $r['sku'] }}</span>@endif @if ($r['ai_generated'])<span class="rounded bg-purple-50 px-1 text-xs text-purple-700">AI</span>@endif</span>
                <span class="text-xs text-gray-600">{{ number_format($r['price_minor'] / 100, 2) }} {{ $r['currency'] }} · Stock {{ $r['stock'] }} · {{ $r['variants'] }} Variants · {{ $statusBn[$r['status']] ?? $r['status'] }}</span>
            </a>
        @empty<p class="text-sm text-gray-500">No products.@if ($canManage) <a class="text-indigo-600" href="{{ route('catalog.create') }}">Add the first product</a> or <a class="text-indigo-600" href="{{ route('catalog.import') }}">CSV to bring them in together</a>.@endif</p>@endforelse
    </section>
</x-layouts.app>
