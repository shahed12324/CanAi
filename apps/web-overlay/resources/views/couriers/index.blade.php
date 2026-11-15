<x-layouts.app title="Courier">
    {{-- JARVIS ONE: page-specific visual identity; existing actions preserved --}}
    <header class="jarvis-module-hero jarvis-accent-violet" data-jarvis-module="couriers" aria-label="Courier">
      <div class="jarvis-module-hero__identity"><span class="jarvis-module-hero__glyph" aria-hidden="true">✦</span><div>
        <div class="jarvis-module-hero__eyebrow">JARVIS ONE <span aria-hidden="true">/</span> COURIERS</div>
        <h1>Courier</h1>
      </div></div>
      <span class="jarvis-module-hero__section">Couriers Workspace</span>
    </header>

    <div class="flex items-center justify-between"><h2 class="text-xl font-semibold">Courier accounts</h2><a class="text-sm text-indigo-600" href="{{ route('orders.index') }}">← Order</a></div>
    @if (session('status'))<p class="rounded bg-green-50 p-3 text-sm text-green-700">{{ session('status') }}</p>@endif
    <p class="rounded bg-amber-50 p-3 text-sm text-amber-800">Keys are stored encrypted and are not shown again. To change, enter them all again. Test first with your own sandbox/a small order.</p>
    @foreach ($fields as $courier => $names)
        @php($a = $accounts[$courier] ?? null)
        <section class="space-y-3 rounded-lg bg-white p-5 text-sm shadow">
            <div class="flex items-center justify-between"><h3 class="font-semibold">{{ \App\Couriers\CourierText::name($courier) }}
                @if ($a)<span class="ml-2 rounded px-2 py-0.5 text-xs {{ $a['status'] === 'active' ? 'bg-green-100 text-green-800' : 'bg-gray-200' }}">{{ $a['status'] === 'active' ? 'Connected' : 'Closed' }}</span>@endif</h3>
                @if ($a)<form method="POST" action="{{ route('couriers.toggle', $a['id']) }}">@csrf<input type="hidden" name="status" value="{{ $a['status'] === 'active' ? 'disabled' : 'active' }}"><button class="rounded border px-2 py-1 text-xs">{{ $a['status'] === 'active' ? 'Deactivate' : 'Activate' }}</button></form>@endif</div>
            <form method="POST" action="{{ route('couriers.save', $courier) }}" class="grid gap-2 sm:grid-cols-2">@csrf
                @foreach ($names as $f)
                    <label class="block">{{ \App\Couriers\CourierText::fieldLabel($f) }}<input name="{{ $f }}" type="{{ in_array($f, ['password', 'secret_key', 'client_secret', 'access_token'], true) ? 'password' : 'text' }}" autocomplete="off" required maxlength="300" class="mt-1 w-full rounded border px-2 py-1"></label>
                @endforeach
                @if ($courier === 'pathao')<label class="block">Store ID<input name="store_id" inputmode="numeric" required value="{{ old('store_id', $a['settings']['store_id'] ?? '') }}" class="mt-1 w-full rounded border px-2 py-1"></label>@endif
                @if ($courier === 'redx')<label class="block">Pickup Store ID (optional)<input name="pickup_store_id" inputmode="numeric" value="{{ old('pickup_store_id', $a['settings']['pickup_store_id'] ?? '') }}" class="mt-1 w-full rounded border px-2 py-1"></label>@endif
                @if ($courier !== 'steadfast')<label class="flex items-center gap-2"><input type="checkbox" name="sandbox" value="1" @checked($a['settings']['sandbox'] ?? false)> Sandbox (test server)</label>@endif
                <div class="sm:col-span-2"><button class="rounded bg-indigo-600 px-3 py-1 text-white">Save</button></div>
            </form>
            @error('store_id')<p class="text-red-700">{{ $message }}</p>@enderror
        </section>
    @endforeach
</x-layouts.app>
