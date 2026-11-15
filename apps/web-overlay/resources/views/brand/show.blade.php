<x-layouts.app title="Brand kit">
    {{-- JARVIS ONE: page-specific visual identity; existing actions preserved --}}
    <header class="jarvis-module-hero jarvis-accent-violet" data-jarvis-module="brand" aria-label="Brand kit">
      <div class="jarvis-module-hero__identity"><span class="jarvis-module-hero__glyph" aria-hidden="true">✦</span><div>
        <div class="jarvis-module-hero__eyebrow">JARVIS ONE <span aria-hidden="true">/</span> BRAND</div>
        <h1>Brand kit</h1>
      </div></div>
      <span class="jarvis-module-hero__section">Brand Workspace</span>
    </header>

    <div class="flex items-center justify-between"><h2 class="text-xl font-semibold">Brand kit</h2><div class="flex gap-3 text-sm"><a class="text-indigo-600" href="{{ route('portfolio.index') }}">Portfolio</a><a class="text-indigo-600" href="{{ route('proposals.index') }}">Proposals</a></div></div>
    @if (session('status'))<p class="rounded bg-green-50 p-3 text-sm text-green-700">{{ session('status') }}</p>@endif
    @if ($errors->any())<div class="rounded bg-red-50 p-3 text-sm text-red-700">@foreach ($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div>@endif
    <section class="max-w-2xl space-y-3 rounded-lg bg-white p-5 shadow">
        <p class="text-sm text-gray-600">This name, colors, logo and contact details go into the proposal PDF/DOCX.</p>
        <form method="POST" action="{{ route('brand.update') }}" enctype="multipart/form-data" class="space-y-3">@csrf
            <x-field name="name" label="Organization name" :value="old('name', $kit->name ?? '')" />
            <div class="flex flex-wrap gap-4 text-sm">
                <label>Primary color <input type="color" name="primary_color" value="{{ old('primary_color', $kit->primary_color ?? '#1f3a5f') }}" class="h-9 w-16 rounded border"></label>
                <label>Accent color <input type="color" name="accent_color" value="{{ old('accent_color', $kit->accent_color ?? '#e07a1f') }}" class="h-9 w-16 rounded border"></label>
            </div>
            <x-field name="email" label="Email" :value="old('email', $kit->email ?? '')" />
            <x-field name="phone" label="Phone" :value="old('phone', $kit->phone ?? '')" />
            <x-field name="website" label="Website (https://…)" :value="old('website', $kit->website ?? '')" />
            <x-field name="address" label="Address" :value="old('address', $kit->address ?? '')" />
            <x-field name="tone" label="Writing tone (for example: friendly and professional)" :value="old('tone', $kit->tone ?? '')" />
            <div class="text-sm"><label class="block font-medium text-gray-700">Logo (PNG/JPEG, up to 600KB)</label>
                @if ($kit && $kit->has_logo)<img alt="Logo" class="my-2 max-h-16 rounded border bg-white p-1" src="data:image/png;base64,{{ $kit->logo_b64 }}"><label class="flex items-center gap-1"><input type="checkbox" name="remove_logo" value="1"> Remove logo</label>@endif
                <input type="file" name="logo" accept="image/png,image/jpeg" class="mt-1"></div>
            @if ($canManage)<x-button>Save</x-button>@endif
        </form>
    </section>
</x-layouts.app>
