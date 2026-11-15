@php
    $m = fn ($v) => $v === null ? '' : number_format($v / 100, 2, '.', '');
    $f = fn ($k, $d = '') => old($k, $p[$k] ?? $d);
    $status = $p['status'] ?? 'draft';
    $locked = $status === 'archived' || ! $canManage;
@endphp
<x-layouts.app :title="$p ? $p['title'] : 'New product'">
    {{-- JARVIS ONE: page-specific visual identity; existing actions preserved --}}
    <header class="jarvis-module-hero jarvis-accent-violet" data-jarvis-module="catalog" aria-label="Edit Catalog">
      <div class="jarvis-module-hero__identity"><span class="jarvis-module-hero__glyph" aria-hidden="true">✦</span><div>
        <div class="jarvis-module-hero__eyebrow">JARVIS ONE <span aria-hidden="true">/</span> CATALOG</div>
        <h1>Edit Catalog</h1>
      </div></div>
      <span class="jarvis-module-hero__section">Catalog Workspace</span>
    </header>

    <div class="flex items-center justify-between"><h2 class="text-xl font-semibold">{{ $p ? $p['title'] : 'New product' }}@if ($p) <span class="ml-2 rounded bg-gray-100 px-2 py-0.5 text-xs font-normal">{{ $statusBn[$status] }}</span>@endif</h2><a class="text-sm text-indigo-600" href="{{ route('catalog.index') }}">← Product</a></div>
    @if (session('status'))<p class="rounded bg-green-50 p-3 text-sm text-green-700">{{ session('status') }}</p>@endif
    @if (session('error'))<p class="rounded bg-red-50 p-3 text-sm text-red-700">{{ session('error') }}</p>@endif
    @if ($errors->any())<p class="rounded bg-red-50 p-3 text-sm text-red-700">{{ $errors->first() }}</p>@endif
    @if ($p && $p['review_note'] && $status === 'draft')<p class="rounded bg-yellow-50 p-3 text-sm text-yellow-800">Reason for return: {{ $p['review_note'] }}</p>@endif

    <form id="pf" method="POST" action="{{ $p ? route('catalog.update', $p['id']) : route('catalog.store') }}" class="space-y-3 rounded-lg bg-white p-5 text-sm shadow">@csrf
        <input type="hidden" name="ai_generated" id="ai_generated" value="{{ $f('ai_generated') ? 1 : '' }}">
        <label class="block">Title<input name="title" value="{{ $f('title') }}" required maxlength="250" class="mt-1 w-full rounded border px-2 py-1" @disabled($locked)></label>
        <div class="grid gap-3 sm:grid-cols-4">
            <label class="block">Price<input name="price" value="{{ old('price', $m($p['price_minor'] ?? null)) }}" class="mt-1 w-full rounded border px-2 py-1" @disabled($locked)></label>
            <label class="block">Sale price<input name="sale_price" value="{{ old('sale_price', $m($p['sale_price_minor'] ?? null)) }}" class="mt-1 w-full rounded border px-2 py-1" @disabled($locked)></label>
            <label class="block">Currency<input name="currency" value="{{ $f('currency', 'BDT') }}" maxlength="3" class="mt-1 w-full rounded border px-2 py-1" @disabled($locked)></label>
            <label class="block">SKU<input name="sku" value="{{ $f('sku') }}" maxlength="80" class="mt-1 w-full rounded border px-2 py-1" @disabled($locked)></label>
        </div>
        <div class="grid gap-3 sm:grid-cols-2">
            <label class="block">Category<input name="category" value="{{ $f('category') }}" maxlength="120" class="mt-1 w-full rounded border px-2 py-1" @disabled($locked)></label>
            <label class="block">Tags (comma-separated)<input name="tags" value="{{ old('tags', implode(', ', $p['tags'] ?? [])) }}" class="mt-1 w-full rounded border px-2 py-1" @disabled($locked)></label>
        </div>
        <label class="block">Image links (one per line, max 10)<textarea name="images" rows="2" class="mt-1 w-full rounded border px-2 py-1" @disabled($locked)>{{ old('images', implode("\n", $p['images'] ?? [])) }}</textarea></label>
        <label class="block">Description<textarea id="description" name="description" rows="6" class="mt-1 w-full rounded border px-2 py-1" @disabled($locked)>{{ $f('description') }}</textarea></label>
        <div class="grid gap-3 sm:grid-cols-2">
            <label class="block">SEO Title<input id="seo_title" name="seo_title" value="{{ old('seo_title', $p['seo']['seo_title'] ?? '') }}" maxlength="70" class="mt-1 w-full rounded border px-2 py-1" @disabled($locked)></label>
            <label class="block">SEO Description<input id="seo_description" name="seo_description" value="{{ old('seo_description', $p['seo']['seo_description'] ?? '') }}" maxlength="180" class="mt-1 w-full rounded border px-2 py-1" @disabled($locked)></label>
        </div>
        @unless ($locked)<div class="flex flex-wrap items-center gap-3"><button class="rounded bg-indigo-600 px-3 py-1 text-white">Save</button>
            <button type="button" id="aiBtn" class="rounded border border-purple-300 px-3 py-1 text-purple-700">AI Write description with</button><span id="aiMsg" class="text-xs text-gray-600"></span></div>
            <p class="text-xs text-gray-500">AI-text is deducted from your credits and only gives a draft — it is kept only if you review and save it, and approval is still needed after that.</p>@endunless
    </form>

    @if ($p)
    <section class="space-y-2 rounded-lg bg-white p-5 text-sm shadow"><h3 class="font-medium">Variants and stock</h3>
        @foreach ($p['variants'] as $v)
            <div class="flex flex-wrap items-center justify-between gap-2 border-t py-2 first:border-0">
                <span>{{ $v['options'] === [] ? 'Single product' : collect($v['options'])->map(fn ($x, $k) => "$k: $x")->implode(' · ') }}@if ($v['sku']) <span class="text-gray-500">· {{ $v['sku'] }}</span>@endif @if ($v['price_minor'] !== null) <span class="text-gray-500">· {{ $m($v['price_minor']) }}</span>@endif</span>
                <span class="flex items-center gap-2">
                    @if ($v['track_stock'])<strong>Stock {{ $v['stock_qty'] }}</strong>
                        @if ($canManage && $status !== 'archived')<form method="POST" action="{{ route('catalog.stock', [$p['id'], trim($v['id'])]) }}" class="flex items-center gap-1">@csrf
                            <input name="delta" type="number" required placeholder="+5 / -2" class="w-20 rounded border px-1 py-0.5">
                            <select name="reason" class="rounded border px-1 py-0.5"><option value="adjust">Adjustment</option><option value="sale">Sale</option><option value="return"> return</option><option value="correction">Correction</option></select>
                            <button class="rounded bg-gray-800 px-2 py-0.5 text-white">OK</button></form>@endif
                    @else<span class="text-gray-500">Stock is not tracked</span>@endif
                    @if ($canManage && $status !== 'archived' && count($p['variants']) > 1)<form method="POST" action="{{ route('catalog.variants.archive', [$p['id'], trim($v['id'])]) }}">@csrf<button class="text-xs text-red-600">Remove</button></form>@endif
                </span>
            </div>
        @endforeach
        @if ($canManage && $status !== 'archived')
        <form method="POST" action="{{ route('catalog.variants.store', $p['id']) }}" class="flex flex-wrap items-end gap-2 border-t pt-3">@csrf
            <label class="text-xs">Option<input name="options" placeholder="Size=M;Color=Red" required class="mt-1 block rounded border px-2 py-1 text-sm"></label>
            <label class="text-xs">SKU<input name="sku" class="mt-1 block w-28 rounded border px-2 py-1 text-sm"></label>
            <label class="text-xs">Price (empty = product price)<input name="price" class="mt-1 block w-24 rounded border px-2 py-1 text-sm"></label>
            <label class="text-xs">Opening stock<input name="stock" type="number" min="0" value="0" class="mt-1 block w-20 rounded border px-2 py-1 text-sm"></label>
            <button class="rounded bg-gray-800 px-3 py-1 text-white">Add variant</button>
        </form>@endif
    </section>

    <section class="space-y-2 rounded-lg bg-white p-5 text-sm shadow"><h3 class="font-medium">Approval and publishing</h3>
        <div class="flex flex-wrap items-center gap-2">
            @if ($status === 'draft' && $canManage)<form method="POST" action="{{ route('catalog.flow', [$p['id'], 'submit']) }}">@csrf<button class="rounded bg-indigo-600 px-3 py-1 text-white">Send for approval</button></form>@endif
            @if ($status === 'pending_approval' && $canApprove)
                <form method="POST" action="{{ route('catalog.flow', [$p['id'], 'approve']) }}">@csrf<button class="rounded bg-green-600 px-3 py-1 text-white">Approval</button></form>
                <form method="POST" action="{{ route('catalog.flow', [$p['id'], 'reject']) }}" class="flex gap-1">@csrf<input name="note" placeholder="Reason for return" class="rounded border px-2 py-1"><button class="rounded bg-yellow-600 px-3 py-1 text-white"> return</button></form>
            @endif
            @if ($status === 'approved' && $canApprove)<form method="POST" action="{{ route('catalog.flow', [$p['id'], 'publish']) }}">@csrf<button class="rounded bg-green-600 px-3 py-1 text-white">Publish</button></form>@endif
            @if ($status !== 'archived' && $canManage)<form method="POST" action="{{ route('catalog.flow', [$p['id'], 'archive']) }}" onsubmit="return confirm('Archiving will stop sales. Sure?')">@csrf<button class="rounded border px-3 py-1 text-red-700">Archive</button></form>@endif
            @if ($status === 'archived' && $canManage)<form method="POST" action="{{ route('catalog.flow', [$p['id'], 'restore']) }}">@csrf<button class="rounded border px-3 py-1">Restore</button></form>
                <form method="POST" action="{{ route('catalog.destroy', $p['id']) }}" onsubmit="return confirm('The product will be deleted. Sure?')">@csrf<button class="rounded border px-3 py-1 text-red-700">Delete</button></form>@endif
        </div>
        @if ($status === 'published' && $p['live'])<p class="text-xs text-gray-600">What customers see now: “{{ $p['live']['title'] }}” — {{ $m($p['live']['price_minor']) }} {{ $p['live']['currency'] }}. Editing does not change this copy; it changes after re-approval and publishing.</p>@endif
        @foreach ($events as $e)<p class="border-t py-1 text-xs text-gray-600 first:border-0">{{ ['submitted' => 'Sent', 'approved' => 'Allowed', 'rejected' => 'return', 'published' => 'Published', 'archived' => 'Archive', 'restored' => 'Rolled back'][$e['action']] ?? $e['action'] }} · {{ $e['user_name'] ?? '—' }} · {{ \Illuminate\Support\Carbon::parse($e['created_at'])->diffForHumans() }}@if ($e['note']) · {{ $e['note'] }}@endif</p>@endforeach
    </section>
    @endif

    @unless ($locked)
    <script>
    (function () {
        var btn = document.getElementById('aiBtn'); if (!btn) return;
        var msg = document.getElementById('aiMsg'), form = document.getElementById('pf');
        btn.addEventListener('click', function () {
            msg.textContent = 'Writing…'; btn.disabled = true;
            var body = new URLSearchParams({title: form.title.value, category: form.category.value, tags: form.tags.value, price: form.price.value, notes: form.description.value, language: 'bn'});
            fetch(@json(route('catalog.ai')), {method: 'POST', headers: {'X-CSRF-TOKEN': document.querySelector('input[name=_token]').value, 'Accept': 'application/json'}, body: body})
                .then(function (r) { return r.json().then(function (j) { return [r.ok, j]; }); })
                .then(function (x) {
                    btn.disabled = false;
                    if (!x[0]) { msg.textContent = x[1].message || 'AI Cannot write right now.'; return; }
                    var d = x[1].data;
                    if (form.description.value.trim() && !confirm('Replace the current description with AI-written text?')) { msg.textContent = 'Cancelled.'; return; }
                    form.description.value = d.description; form.seo_title.value = d.seo_title; form.seo_description.value = d.seo_description;
                    document.getElementById('ai_generated').value = '1';
                    msg.textContent = 'Review it and save if it is fine. (Cost ' + d.credits + ' credits)';
                }).catch(function () { btn.disabled = false; msg.textContent = 'Connection problem.'; });
        });
    })();
    </script>
    @endunless
</x-layouts.app>
