<x-layouts.app title="Store connection">
    {{-- JARVIS ONE: page-specific visual identity; existing actions preserved --}}
    <header class="jarvis-module-hero jarvis-accent-violet" data-jarvis-module="catalog" aria-label="Store connection">
      <div class="jarvis-module-hero__identity"><span class="jarvis-module-hero__glyph" aria-hidden="true">✦</span><div>
        <div class="jarvis-module-hero__eyebrow">JARVIS ONE <span aria-hidden="true">/</span> CATALOG</div>
        <h1>Store connection</h1>
      </div></div>
      <span class="jarvis-module-hero__section">Catalog Workspace</span>
    </header>

    <div class="flex items-center justify-between"><h2 class="text-xl font-semibold">Store connection</h2><a class="text-sm text-indigo-600" href="{{ route('catalog.index') }}">← Product</a></div>
    @if (session('status'))<p class="rounded bg-green-50 p-3 text-sm text-green-700">{{ session('status') }}</p>@endif
    @if (session('error'))<p class="rounded bg-red-50 p-3 text-sm text-red-700">{{ session('error') }}</p>@endif
    @if ($errors->any())<p class="rounded bg-red-50 p-3 text-sm text-red-700">{{ $errors->first() }}</p>@endif
    <p class="rounded bg-gray-50 p-3 text-xs text-gray-600">Products coming from a store always enter as <strong> draft</strong> . The store only receives your <strong> approved and published</strong> copy. If the emergency stop is on, nothing is sent to the store. Keys/tokens are stored encrypted and are not shown again.</p>

    @foreach ($stores as $s)
    <section class="space-y-2 rounded-lg bg-white p-5 text-sm shadow">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h3 class="font-medium">{{ $s['name'] }} <span class="ml-1 rounded bg-gray-100 px-2 py-0.5 text-xs font-normal">{{ $platformBn[$s['platform']] ?? $s['platform'] }}</span>
                <span class="ml-1 rounded px-2 py-0.5 text-xs font-normal {{ $s['status'] === 'active' ? 'bg-green-50 text-green-700' : ($s['status'] === 'paused' ? 'bg-yellow-50 text-yellow-800' : 'bg-red-50 text-red-700') }}">{{ ['active' => 'Active', 'paused' => 'Closed', 'error' => 'Problem'][$s['status']] ?? $s['status'] }}</span></h3>
            <span class="flex gap-2">
                @if ($canSync && $s['status'] !== 'paused')<form method="POST" action="{{ route('catalog.stores.sync', $s['id']) }}">@csrf<button class="rounded bg-indigo-600 px-3 py-1 text-white">Sync now</button></form>@endif
                @if ($canManage)<form method="POST" action="{{ route('catalog.stores.toggle', $s['id']) }}">@csrf<button class="rounded border px-3 py-1">{{ $s['status'] === 'paused' ? 'Activate' : 'Keep off' }}</button></form>@endif
            </span>
        </div>
        <p class="text-xs text-gray-600">{{ $s['base_url'] }} · {{ $s['currency'] }} · Last sync: {{ $s['last_sync_at'] ? \Illuminate\Support\Carbon::parse($s['last_sync_at'])->diffForHumans() : 'Not yet' }}</p>
        @if ($s['last_error'])<p class="text-xs text-red-700">{{ $s['last_error'] }}</p>@endif
        @foreach ($s['runs'] as $r)<p class="border-t py-1 text-xs text-gray-600">{{ $r['trigger'] === 'manual' ? 'Manual' : 'Automatic' }} · {{ ['running' => 'Running', 'ok' => 'Successful', 'partial' => 'Partial', 'failed' => 'Failed'][$r['status']] ?? $r['status'] }} · Read {{ $r['pulled'] }}, New {{ $r['created'] }}, Update {{ $r['updated'] }}, Sent {{ $r['pushed'] }}, Failed {{ $r['failed'] }}{{ $r['note'] ? ' · '.$r['note'] : '' }} · {{ \Illuminate\Support\Carbon::parse($r['started_at'])->diffForHumans() }}</p>@endforeach
        @if ($canManage)
        <details class="border-t pt-2"><summary class="cursor-pointer text-xs text-indigo-600">Edit / enter new key</summary>
            <form method="POST" action="{{ route('catalog.stores.update', $s['id']) }}" class="mt-2 grid gap-2 sm:grid-cols-2">@csrf
                <label class="text-xs">Name<input name="name" value="{{ $s['name'] }}" required class="mt-1 block w-full rounded border px-2 py-1 text-sm"></label>
                <label class="text-xs">Address<input name="address" value="{{ $s['base_url'] }}" required class="mt-1 block w-full rounded border px-2 py-1 text-sm"></label>
                <label class="text-xs">Currency<input name="currency" value="{{ $s['currency'] }}" maxlength="3" required class="mt-1 block w-full rounded border px-2 py-1 text-sm"></label>
                @if ($s['platform'] === 'shopify')<label class="text-xs">New Access Token (empty = keep the old one)<input name="token" autocomplete="off" class="mt-1 block w-full rounded border px-2 py-1 text-sm"></label>
                @elseif ($s['platform'] === 'jarvis_connector')<label class="text-xs">New Connector Secret (empty = keep the old one)<input name="secret" autocomplete="off" class="mt-1 block w-full rounded border px-2 py-1 text-sm"></label>
                @else<label class="text-xs">New Consumer Key (empty = keep the old one)<input name="key" autocomplete="off" class="mt-1 block w-full rounded border px-2 py-1 text-sm"></label>
                <label class="text-xs">New Consumer Secret<input name="secret" autocomplete="off" class="mt-1 block w-full rounded border px-2 py-1 text-sm"></label>@endif
                <div><button class="rounded bg-gray-800 px-3 py-1 text-white">Save</button></div>
            </form></details>
        @endif
    </section>
    @endforeach
    @if ($stores === [])<p class="text-sm text-gray-500">No store connected yet.</p>@endif

    @if ($canManage)
    <section class="space-y-3 rounded-lg bg-white p-5 text-sm shadow"><h3 class="font-medium">Connect a new store</h3>
        <form method="POST" action="{{ route('catalog.stores.store') }}" class="grid gap-3 sm:grid-cols-2" x-data>@csrf
            <label class="text-xs">Platform<select name="platform" id="plat" class="mt-1 block w-full rounded border px-2 py-1 text-sm"><option value="woocommerce" @selected(old('platform') === 'woocommerce')>WooCommerce</option><option value="jarvis_connector" @selected(old('platform') === 'jarvis_connector')>JARVIS Connector (own Laravel shop)</option><option value="shopify" @selected(old('platform') === 'shopify')>Shopify</option></select></label>
            <label class="text-xs">Name<input name="name" value="{{ old('name') }}" required class="mt-1 block w-full rounded border px-2 py-1 text-sm"></label>
            <label class="text-xs">Address (https://yourshop.com or mystore.myshopify.com)<input name="address" value="{{ old('address') }}" required class="mt-1 block w-full rounded border px-2 py-1 text-sm"></label>
            <label class="text-xs">Currency<input name="currency" value="{{ old('currency', 'BDT') }}" maxlength="3" class="mt-1 block w-full rounded border px-2 py-1 text-sm"></label>
            <label class="text-xs woo wkey">Consumer Key<input name="key" autocomplete="off" class="mt-1 block w-full rounded border px-2 py-1 text-sm"></label>
            <label class="text-xs woo wsec">Consumer Secret/Connector Secret<input name="secret" autocomplete="off" class="mt-1 block w-full rounded border px-2 py-1 text-sm"></label>
            <label class="text-xs shop" style="display:none">Admin API Access Token<input name="token" autocomplete="off" class="mt-1 block w-full rounded border px-2 py-1 text-sm"></label>
            <div class="sm:col-span-2"><button class="rounded bg-indigo-600 px-3 py-1 text-white">Verify and connect</button></div>
        </form>
        <div class="space-y-1 text-xs text-gray-600">
            <p><strong>WooCommerce:</strong> WP Admin → WooCommerce → Settings → Advanced → REST API → Add key (Permissions: Read/Write). The site must use https.</p>
            <p><strong>JARVIS Connector:</strong> In your Laravel shop <code>jarvis/connector</code> install the package, <code>php artisan jarvis-connector:secret</code> create a secret with, and enter the shop address and that secret here (install guide: packages/jarvis-connector/README.md).</p>
            <p><strong>Shopify:</strong> Admin → Settings → Apps → Develop apps → Create a custom app; Admin API scopes: read_products, write_products, read_inventory, write_inventory → Install → Admin API access token.</p>
        </div>
    </section>
    <script>(function(){var p=document.getElementById('plat');function f(){var s=p.value==='shopify',c=p.value==='jarvis_connector';document.querySelectorAll('.wkey').forEach(function(e){e.style.display=(s||c)?'none':''});document.querySelectorAll('.woo').forEach(function(e){e.style.display=s?'none':''});document.querySelectorAll('.shop').forEach(function(e){e.style.display=s?'':'none'})}p.addEventListener('change',f);f();})();</script>
    @endif
</x-layouts.app>
