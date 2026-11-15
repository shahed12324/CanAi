<x-layouts.app title="Customers/Suppliers">
    {{-- JARVIS ONE: page-specific visual identity; existing actions preserved --}}
    <header class="jarvis-module-hero jarvis-accent-violet" data-jarvis-module="accounting" aria-label="Customers/Suppliers">
      <div class="jarvis-module-hero__identity"><span class="jarvis-module-hero__glyph" aria-hidden="true">✦</span><div>
        <div class="jarvis-module-hero__eyebrow">JARVIS ONE <span aria-hidden="true">/</span> ACCOUNTING</div>
        <h1>Customers/Suppliers</h1><p>Review transactions, balances and accounting workflows.</p>
      </div></div>
      <span class="jarvis-module-hero__section">Accounting Workspace</span>
    </header>

    <h2 class="text-xl font-semibold">Customers and suppliers</h2>
    @include('accounting._nav')
    <div class="overflow-x-auto rounded-lg bg-white shadow"><table class="min-w-full text-sm"><thead class="bg-gray-50 text-left text-xs text-gray-500"><tr><th class="px-3 py-2">Name</th><th class="px-3 py-2">Type</th><th class="px-3 py-2">Phone</th><th class="px-3 py-2"></th></tr></thead><tbody>
        @forelse ($parties as $p)<tr class="border-t"><td class="px-3 py-2">{{ $p['name'] }}@unless ($p['active']) <span class="text-xs text-red-600">(inactive)</span>@endunless</td><td class="px-3 py-2">{{ $p['kind'] === 'customer' ? 'Customer' : 'Supplier' }}</td><td class="px-3 py-2">{{ $p['phone'] }}</td>
            <td class="px-3 py-2">@if ($canManage)<form method="POST" action="{{ route('accounting.parties.active', $p['id']) }}">@csrf<input type="hidden" name="active" value="{{ $p['active'] ? 0 : 1 }}"><button class="text-xs text-indigo-600">{{ $p['active'] ? 'Deactivate' : 'Activate' }}</button></form>@endif</td></tr>
        @empty<tr><td colspan="4" class="px-3 py-6 text-center text-gray-500">No one.</td></tr>@endforelse</tbody></table></div>
    @if ($canManage)<form method="POST" action="{{ route('accounting.parties.add') }}" class="grid gap-2 rounded-lg bg-white p-5 text-sm shadow sm:grid-cols-5">@csrf
        <select name="kind" class="rounded border px-2 py-1"><option value="customer">Customer</option><option value="supplier">Supplier</option></select><input name="name" required maxlength="120" placeholder="Name" class="rounded border px-2 py-1"><input name="phone" maxlength="30" placeholder="Phone" class="rounded border px-2 py-1"><input name="note" maxlength="200" placeholder="Note" class="rounded border px-2 py-1"><button class="rounded bg-indigo-600 px-3 py-1 text-white">Add</button></form>@endif
</x-layouts.app>
