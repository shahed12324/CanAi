<x-layouts.app title="New order">
    {{-- JARVIS ONE: page-specific visual identity; existing actions preserved --}}
    <header class="jarvis-module-hero jarvis-accent-violet" data-jarvis-module="orders" aria-label="New order">
      <div class="jarvis-module-hero__identity"><span class="jarvis-module-hero__glyph" aria-hidden="true">✦</span><div>
        <div class="jarvis-module-hero__eyebrow">JARVIS ONE <span aria-hidden="true">/</span> ORDERS</div>
        <h1>New order</h1><p>Track orders, fulfillment and customer activity.</p>
      </div></div>
      <span class="jarvis-module-hero__section">Orders Workspace</span>
    </header>

    <div class="flex items-center justify-between"><h2 class="text-xl font-semibold">New order (cash on delivery)</h2><a class="text-sm text-indigo-600" href="{{ route('orders.index') }}">← Order</a></div>
    @error('order')<p class="rounded bg-red-50 p-3 text-sm text-red-700">{{ $message }}</p>@enderror
    @if (! count($variants))<p class="rounded bg-amber-50 p-3 text-sm text-amber-800">There are no published products for sale. First <a class="underline" href="{{ route('catalog.index') }}">Product</a> publish.</p>@endif
    <form method="POST" action="{{ route('orders.store') }}" class="space-y-4 rounded-lg bg-white p-5 text-sm shadow">@csrf
        <p class="text-gray-600">You do not set the price — the system fills it from the published (approved) copy. Stock is held immediately.</p>
        @for ($i = 0; $i < 5; $i++)
            <div class="flex gap-2">
                <select name="lines[{{ $i }}][variant_id]" class="min-w-0 flex-1 rounded border px-2 py-1"><option value="">— Product —</option>
                    @foreach ($variants as $v)<option value="{{ $v['id'] }}" @selected(old("lines.$i.variant_id") === $v['id'])>{{ $v['title'] }}@if ($v['options']) ({{ collect($v['options'])->map(fn ($x, $k) => "$k: $x")->implode(', ') }})@endif — {{ \App\Orders\OrderText::money($v['price_minor'], $v['currency']) }}@if ($v['track_stock']) · Stock {{ $v['stock_qty'] }}@endif</option>@endforeach
                </select>
                <input type="number" min="1" max="100" name="lines[{{ $i }}][qty]" value="{{ old("lines.$i.qty", $i === 0 ? 1 : '') }}" class="w-20 rounded border px-2 py-1" placeholder="Quantity">
            </div>
        @endfor
        <div class="grid gap-3 sm:grid-cols-2">
            <label>Customer name <input name="customer_name" value="{{ old('customer_name') }}" required maxlength="120" class="mt-1 block w-full rounded border px-2 py-1"></label>
            <label>Mobile <input name="phone" value="{{ old('phone') }}" required maxlength="30" placeholder="01712345678" class="mt-1 block w-full rounded border px-2 py-1"></label>
        </div>
        <label class="block">Address <textarea name="address" rows="2" required maxlength="500" class="mt-1 block w-full rounded border px-2 py-1">{{ old('address') }}</textarea></label>
        <div class="grid gap-3 sm:grid-cols-3">
            <label>Delivery area <select name="zone" class="mt-1 block w-full rounded border px-2 py-1"><option value="inside" @selected(old('zone') === 'inside')>Inside Dhaka ({{ \App\Orders\OrderText::money($settings['delivery_inside_minor']) }})</option><option value="outside" @selected(old('zone') === 'outside')>Outside Dhaka ({{ \App\Orders\OrderText::money($settings['delivery_outside_minor']) }})</option></select></label>
            <label>Discount % (optional) <input type="number" step="0.01" min="0" max="100" name="discount_pct" value="{{ old('discount_pct', 0) }}" class="mt-1 block w-full rounded border px-2 py-1"></label>
            <label>Note <input name="note" value="{{ old('note') }}" maxlength="300" class="mt-1 block w-full rounded border px-2 py-1"></label>
        </div>
        <button class="rounded bg-indigo-600 px-4 py-2 text-white" @disabled(! count($variants))>Create order</button>
    </form>
</x-layouts.app>
