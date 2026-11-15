<x-layouts.app title="Brand">
    {{-- JARVIS ONE: page-specific visual identity; existing actions preserved --}}
    <header class="jarvis-module-hero jarvis-accent-violet" data-jarvis-module="brand" aria-label="Brand">
      <div class="jarvis-module-hero__identity"><span class="jarvis-module-hero__glyph" aria-hidden="true">✦</span><div>
        <div class="jarvis-module-hero__eyebrow">JARVIS ONE <span aria-hidden="true">/</span> BRAND</div>
        <h1>Brand</h1>
      </div></div>
      <span class="jarvis-module-hero__section">Brand Workspace</span>
    </header>

    <h2 class="text-xl font-semibold">Brand (white-label)</h2>
    @if ($errors->has('brand'))<p class="mt-2 rounded bg-red-50 p-3 text-sm text-red-700">{{ $errors->first('brand') }}</p>@endif
    @if (session('status'))<p class="mt-2 rounded bg-green-50 p-3 text-sm text-green-700">{{ session('status') }}</p>@endif
    @if ($b === null || ! $b['enabled'])
        <p class="mt-3 rounded-lg bg-white p-4 text-sm shadow">White-label is not enabled for this workspace. Contact the platform admin to enable it.</p>
    @else
        <form method="POST" action="{{ route('brand.save') }}" class="mt-3 grid max-w-lg gap-3 rounded-lg bg-white p-4 text-sm shadow">@csrf
            <p class="text-xs text-gray-600">Only members of your workspace see this: name, button color and support email. Leave blank to keep the default.</p>
            <label class="grid text-xs">Brand name (max 40)<input name="brand_name" maxlength="40" value="{{ old('brand_name', $b['brand_name']) }}" class="rounded border px-2 py-1 text-sm"></label>
            <label class="grid text-xs">Primary color (#rrggbb, must be clear against white text)<input name="color" maxlength="7" placeholder="#4f46e5" value="{{ old('color', $b['color']) }}" class="rounded border px-2 py-1 text-sm"></label>
            <label class="grid text-xs">Support email<input name="support_email" type="email" maxlength="254" value="{{ old('support_email', $b['support_email']) }}" class="rounded border px-2 py-1 text-sm"></label>
            <label class="flex items-center gap-2 text-xs"><input type="checkbox" name="hide_powered_by" value="1" @checked(old('hide_powered_by', $b['hide_powered_by']))> "Powered by" Hide line</label>
            <div><button class="rounded bg-indigo-600 px-3 py-1 text-white">Save</button></div>
        </form>
    @endif
</x-layouts.app>
