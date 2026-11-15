<x-layouts.admin title="Reseller" :crumbs="['Reseller' => null]">
    {{-- JARVIS ONE: page-specific visual identity; existing actions preserved --}}
    <header class="jarvis-module-hero jarvis-accent-violet" data-jarvis-module="admin" aria-label="Reseller">
      <div class="jarvis-module-hero__identity"><span class="jarvis-module-hero__glyph" aria-hidden="true">✦</span><div>
        <div class="jarvis-module-hero__eyebrow">JARVIS ONE <span aria-hidden="true">/</span> ADMIN</div>
        <h1>Reseller</h1><p>Manage your platform, permissions and operations.</p>
      </div></div>
      <span class="jarvis-module-hero__section">Admin Workspace</span>
    </header>
    <h1 class="text-xl font-semibold">Reseller</h1>
    @if ($errors->any())<p class="rounded bg-red-50 p-3 text-sm text-red-700">{{ $errors->first() }}</p>@endif
    @if (session('status'))<p class="rounded bg-green-50 p-3 text-sm text-green-700">{{ session('status') }}</p>@endif
    <div class="space-y-3">
        @forelse ($resellers as $r)
        <div class="rounded-lg bg-white p-4 text-sm shadow">
            <b>{{ $r['name'] }}</b> <span class="text-xs text-gray-500">{{ $r['email'] }} · Code {{ $r['code'] }} · Customers {{ $r['customers'] }}</span>
            <table class="mt-2 w-full text-left text-xs"><thead><tr><th>Currency</th><th>Commission</th><th>Paid</th><th>Receivable</th></tr></thead><tbody>
                @forelse ($r['lines'] as $l)<tr><td>{{ $l['currency'] }}</td><td>{{ $l['commission'] }}</td><td>{{ $l['paid_out'] }}</td><td><b>{{ $l['due'] }}</b></td></tr>@empty<tr><td colspan="4" class="text-gray-500">—</td></tr>@endforelse</tbody></table>
            <form method="POST" action="{{ route('admin.resellers.update', $r['id']) }}" class="mt-2 flex flex-wrap items-end gap-2">@csrf
                <select name="status" class="rounded border border-gray-300 px-2 py-1"><option value="active" @selected($r['status'] === 'active')>Active</option><option value="suspended" @selected($r['status'] === 'suspended')>Suspended</option></select>
                <input name="rate" type="number" step="0.01" min="0" max="50" value="{{ $r['commission_bp'] / 100 }}" class="w-24 rounded border border-gray-300 px-2 py-1" aria-label="Rate %">
                <button class="rounded bg-gray-700 px-3 py-1 text-white">Save</button></form>
            <form method="POST" action="{{ route('admin.resellers.payout', $r['id']) }}" class="mt-2 flex flex-wrap items-end gap-2">@csrf
                <input name="currency" maxlength="3" value="BDT" class="w-16 rounded border border-gray-300 px-2 py-1 uppercase" aria-label="Currency">
                <input name="amount" type="number" step="0.01" min="0.01" required placeholder="Quantity" class="w-32 rounded border border-gray-300 px-2 py-1">
                <input name="note" maxlength="200" placeholder="Note" class="min-w-0 flex-1 rounded border border-gray-300 px-2 py-1">
                <button class="rounded bg-green-600 px-3 py-1 text-white">Enter payout</button></form>
        </div>
        @empty<p class="text-sm text-gray-500">No resellers.</p>@endforelse
    </div>
    <section class="max-w-md space-y-3 rounded-lg bg-white p-5 shadow"><h2 class="font-medium">New reseller</h2>
        <form method="POST" action="{{ route('admin.resellers.store') }}" class="space-y-3">@csrf
            <x-field name="email" label="User email (already registered)" required />
            <x-field name="name" label="Name" required />
            <x-field name="code" label="Code (6–12 letters/digits)" required />
            <x-field name="rate" label="Commission rate % (0–50)" required />
            <x-button>Add</x-button></form></section>
</x-layouts.admin>
