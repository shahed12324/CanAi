<x-layouts.app title="Product import">
    {{-- JARVIS ONE: page-specific visual identity; existing actions preserved --}}
    <header class="jarvis-module-hero jarvis-accent-violet" data-jarvis-module="catalog" aria-label="Product import">
      <div class="jarvis-module-hero__identity"><span class="jarvis-module-hero__glyph" aria-hidden="true">✦</span><div>
        <div class="jarvis-module-hero__eyebrow">JARVIS ONE <span aria-hidden="true">/</span> CATALOG</div>
        <h1>Product import</h1>
      </div></div>
      <span class="jarvis-module-hero__section">Catalog Workspace</span>
    </header>

    <div class="flex items-center justify-between"><h2 class="text-xl font-semibold">CSV Import products from</h2><a class="text-sm text-indigo-600" href="{{ route('catalog.index') }}">← Product</a></div>
    @if ($errors->any())<p class="rounded bg-red-50 p-3 text-sm text-red-700">{{ $errors->first() }}</p>@endif
    @if ($result)
        <section class="rounded-lg bg-white p-5 text-sm shadow">
            <p><strong>{{ $result['total'] }}</strong> rows · new {{ $result['created'] }} · Update {{ $result['updated'] }} · Failed {{ $result['failed'] }}</p>
            <p class="text-xs text-gray-500">All products are in draft state — each is published after approval.</p>
            @foreach ($result['errors'] as $e)<p class="text-xs text-red-700">Rows {{ $e['row'] }}: {{ $e['message'] }}</p>@endforeach
        </section>
    @endif
    <form method="POST" action="{{ route('catalog.import.store') }}" enctype="multipart/form-data" class="space-y-3 rounded-lg bg-white p-5 text-sm shadow">@csrf
        <input type="file" name="file" accept=".csv,text/csv" required>
        <button class="rounded bg-indigo-600 px-3 py-1 text-white">Import</button>
        <p class="text-xs text-gray-600">Max 2 MB, 2000 rows. Columns: sku, title, description, price, sale_price, currency, category, tags (a|b), images (url|url), stock, options (Size=M;Color=Red), parent_sku.
            parent_sku A row given is a variant of that SKU. Giving the same SKU again updates the product and stock is matched to the new number. <a class="text-indigo-600" href="{{ route('catalog.sample') }}">Download sample CSV</a>.</p>
    </form>
    <section class="rounded-lg bg-white p-5 text-sm shadow"><h3 class="mb-2 font-medium">Previous imports</h3>
        @forelse ($imports as $i)<p class="border-t py-1 text-xs first:border-0">{{ $i['filename'] }} · New {{ $i['created'] }}, Update {{ $i['updated'] }}, Failed {{ $i['failed'] }} · {{ \Illuminate\Support\Carbon::parse($i['created_at'])->diffForHumans() }}</p>@empty<p class="text-xs text-gray-500">None yet.</p>@endforelse
    </section>
</x-layouts.app>
