<x-layouts.app title="Shop profile">
    {{-- JARVIS ONE: page-specific visual identity; existing actions preserved --}}
    <header class="jarvis-module-hero jarvis-accent-violet" data-jarvis-module="settings" aria-label="Shop profile">
      <div class="jarvis-module-hero__identity"><span class="jarvis-module-hero__glyph" aria-hidden="true">✦</span><div>
        <div class="jarvis-module-hero__eyebrow">JARVIS ONE <span aria-hidden="true">/</span> SETTINGS</div>
        <h1>Shop profile</h1>
      </div></div>
      <span class="jarvis-module-hero__section">Settings Workspace</span>
    </header>

    <h2 class="text-xl font-semibold">Shop profile</h2>
    @if (session('status'))<p class="rounded bg-green-50 p-3 text-sm text-green-700">{{ session('status') }}</p>@endif
    @if ($errors->any())<p class="rounded bg-red-50 p-3 text-sm text-red-700">{{ $errors->first() }}</p>@endif
    @if (! $p)<p class="rounded bg-amber-50 p-3 text-sm text-amber-800">Complete your shop profile: name and address appear on your invoices and messages.</p>@endif

    <section class="max-w-2xl space-y-4 rounded-lg bg-white p-5 shadow">
        <h3 class="font-medium">Logo</h3>
        <div class="flex items-center gap-4">
            @if ($hasLogo)
                <img src="{{ route('shop.logo') }}?v={{ $p->updated_at }}" alt="Shop logo" class="h-20 w-20 rounded border object-contain">
            @else
                <div class="flex h-20 w-20 items-center justify-center rounded border bg-gray-50 text-xs text-gray-400">No logo</div>
            @endif
            @if ($canManage && $p)
                <form method="POST" action="{{ route('shop.logo.upload') }}" enctype="multipart/form-data" class="space-y-2">
                    @csrf
                    <input type="file" name="logo" accept="image/png,image/jpeg,image/webp" required class="block text-sm">
                    <p class="text-xs text-gray-500">PNG, JPG or WebP, 32–2000 px, up to 1 MB.</p>
                    <x-button>Upload logo</x-button>
                </form>
                @if ($hasLogo)
                    <form method="POST" action="{{ route('shop.logo.remove') }}">@csrf @method('DELETE') <x-button>Remove</x-button></form>
                @endif
            @elseif ($canManage)
                <p class="text-sm text-gray-500">Save the shop details first, then add a logo.</p>
            @endif
        </div>
    </section>

    <section class="max-w-2xl rounded-lg bg-white p-5 shadow">
        <form method="POST" action="{{ route('shop.save') }}" class="space-y-3">
            @csrf
            <x-field name="shop_name" label="Shop name" :value="old('shop_name', $p->shop_name ?? $defaultName)" required />
            <x-field name="address" label="Address" :value="old('address', $p->address ?? '')" />
            <div class="grid gap-3 sm:grid-cols-2">
                <x-field name="city" label="City" :value="old('city', $p->city ?? '')" />
                <x-field name="country" label="Country" :value="old('country', $p->country ?? '')" />
                <x-field name="phone" label="Phone" :value="old('phone', $p->phone ?? '')" />
                <x-field name="email" label="Shop email" type="email" :value="old('email', $p->email ?? '')" />
            </div>
            <x-field name="website" label="Website (https://…)" :value="old('website', $p->website ?? '')" />
            <label class="block text-sm font-medium">About the shop
                <textarea name="description" rows="3" maxlength="1000" class="mt-1 w-full rounded border-gray-300 text-sm">{{ old('description', $p->description ?? '') }}</textarea>
            </label>
            @if ($canManage)<x-button>Save profile</x-button>@else<p class="text-sm text-gray-500">Only the workspace owner or an admin can edit this.</p>@endif
        </form>
    </section>
</x-layouts.app>
