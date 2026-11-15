<x-layouts.app title="Billing information">
    {{-- JARVIS ONE: page-specific visual identity; existing actions preserved --}}
    <header class="jarvis-module-hero jarvis-accent-violet" data-jarvis-module="billing" aria-label="Billing information">
      <div class="jarvis-module-hero__identity"><span class="jarvis-module-hero__glyph" aria-hidden="true">✦</span><div>
        <div class="jarvis-module-hero__eyebrow">JARVIS ONE <span aria-hidden="true">/</span> BILLING</div>
        <h1>Billing information</h1><p>Manage subscriptions, invoices and payment history.</p>
      </div></div>
      <span class="jarvis-module-hero__section">Billing Workspace</span>
    </header>

    <div class="flex items-center gap-3"><h1 class="text-xl font-semibold">Billing information (printed on invoices)</h1><a class="ml-auto text-sm text-indigo-600" href="{{ route('billing.index') }}">← Billing</a></div>
    @include('billing._flash')
    <form method="POST" action="{{ route('billing.profile.save') }}" class="max-w-lg space-y-3 rounded-lg bg-white p-5 shadow">
        @csrf @method('PUT')
        <x-field name="legal_name" label="Organization / person name" :value="old('legal_name', $p->legal_name ?? '')" required />
        <x-field name="address" label="Address" :value="old('address', $p->address ?? '')" />
        <x-field name="tax_id" label="BIN / TIN (if any)" :value="old('tax_id', $p->tax_id ?? '')" />
        <x-field name="email" type="email" label="Billing email" :value="old('email', $p->email ?? '')" />
        @if ($canManage)<x-button>Save</x-button>@else<p class="text-sm text-gray-500">Only the owner or an admin can change this.</p>@endif
    </form>
</x-layouts.app>
