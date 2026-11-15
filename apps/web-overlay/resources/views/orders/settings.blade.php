<x-layouts.app title="Order settings">
    {{-- JARVIS ONE: page-specific visual identity; existing actions preserved --}}
    <header class="jarvis-module-hero jarvis-accent-violet" data-jarvis-module="orders" aria-label="Order settings">
      <div class="jarvis-module-hero__identity"><span class="jarvis-module-hero__glyph" aria-hidden="true">✦</span><div>
        <div class="jarvis-module-hero__eyebrow">JARVIS ONE <span aria-hidden="true">/</span> ORDERS</div>
        <h1>Order settings</h1><p>Track orders, fulfillment and customer activity.</p>
      </div></div>
      <span class="jarvis-module-hero__section">Orders Workspace</span>
    </header>

    <div class="flex items-center justify-between"><h2 class="text-xl font-semibold">Order settings</h2><a class="text-sm text-indigo-600" href="{{ route('orders.index') }}">← Order</a></div>
    @if (session('status'))<p class="rounded bg-green-50 p-3 text-sm text-green-700">{{ session('status') }}</p>@endif
    <form method="POST" action="{{ route('orders.settings.save') }}" class="space-y-4 rounded-lg bg-white p-5 text-sm shadow">@csrf
        <label class="block">AI Maximum discount % the agent may give (0 = never; max 50)
            <input type="number" name="max_discount_pct" min="0" max="50" value="{{ old('max_discount_pct', $s['max_discount_pct']) }}" class="mt-1 block w-32 rounded border px-2 py-1" @disabled(! $canEdit)></label>
        <div class="grid gap-3 sm:grid-cols-2">
            <label>Delivery charge — inside Dhaka (taka) <input type="number" step="0.01" min="0" name="delivery_inside" value="{{ old('delivery_inside', $s['delivery_inside_minor'] / 100) }}" class="mt-1 block w-full rounded border px-2 py-1" @disabled(! $canEdit)></label>
            <label>Delivery charge — outside Dhaka (taka) <input type="number" step="0.01" min="0" name="delivery_outside" value="{{ old('delivery_outside', $s['delivery_outside_minor'] / 100) }}" class="mt-1 block w-full rounded border px-2 py-1" @disabled(! $canEdit)></label>
        </div>
        <label class="block">Fake-order risk score at which "needs verification" is shown (1–100; default 50)
            <input type="number" name="review_score" min="1" max="100" value="{{ old('review_score', $s['review_score']) }}" class="mt-1 block w-32 rounded border px-2 py-1" @disabled(! $canEdit)></label>
        <label class="flex items-start gap-2"><input type="hidden" name="chat_orders" value="0"><input type="checkbox" name="chat_orders" value="1" class="mt-1" @checked(old('chat_orders', $s['chat_orders'] ? '1' : '0') === '1') @disabled(! $canEdit)>
            <span>AI can take orders in chat (only in <b>Auto</b> mode channels). The AI collects name/phone/address and shows a summary; when the customer says "Yes" order "awaiting confirmation" is created — your team verifies by phone and confirms. When off, the chat goes to a human as soon as an order is mentioned.</span></label>
        @if ($canEdit)<button class="rounded bg-indigo-600 px-4 py-2 text-white">Save</button>@else<p class="text-gray-500">Only the owner/admin can change this.</p>@endif
        @foreach ($errors->all() as $e)<p class="text-red-600">{{ $e }}</p>@endforeach
    </form>
</x-layouts.app>
