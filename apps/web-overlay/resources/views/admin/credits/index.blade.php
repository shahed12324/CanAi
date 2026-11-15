<x-layouts.admin title="Credits" :crumbs="['Credits' => null]">
    {{-- JARVIS ONE: page-specific visual identity; existing actions preserved --}}
    <header class="jarvis-module-hero jarvis-accent-violet" data-jarvis-module="admin" aria-label="Credits">
      <div class="jarvis-module-hero__identity"><span class="jarvis-module-hero__glyph" aria-hidden="true">✦</span><div>
        <div class="jarvis-module-hero__eyebrow">JARVIS ONE <span aria-hidden="true">/</span> ADMIN</div>
        <h1>Credits</h1><p>Manage your platform, permissions and operations.</p>
      </div></div>
      <span class="jarvis-module-hero__section">Admin Workspace</span>
    </header>
    @php($fmt = fn ($v) => \App\Credits\CreditMath::format($v))
    <h1 class="text-xl font-semibold">Credit management</h1>
    @if (session('status'))<p class="rounded bg-green-50 p-3 text-sm text-green-700">{{ session('status') }}</p>@endif
    @if ($errors->any())<div class="rounded bg-red-50 p-3 text-sm text-red-700">@foreach ($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div>@endif

    <div class="grid gap-4 sm:grid-cols-4">
        <div class="rounded-lg bg-white p-4 shadow"><p class="text-xs text-gray-500">Wallet</p><p class="text-2xl font-semibold">{{ number_format($totals->wallets) }}</p></div>
        <div class="rounded-lg bg-white p-4 shadow"><p class="text-xs text-gray-500">Total balance</p><p class="text-2xl font-semibold tabular-nums">{{ $fmt($totals->balance) }}</p></div>
        <div class="rounded-lg bg-white p-4 shadow"><p class="text-xs text-gray-500">Held</p><p class="text-2xl font-semibold tabular-nums">{{ $fmt($totals->reserved) }}</p></div>
        <div class="rounded-lg bg-white p-4 shadow"><p class="text-xs text-gray-500">Pending low-balance alerts</p><p class="text-2xl font-semibold">{{ number_format($openAlerts) }}</p></div>
    </div>

    <section class="rounded-lg bg-white p-5 shadow">
        <h2 class="mb-2 font-medium">Reconciliation (Ledger vs wallet)</h2>
        @if (count($problems) === 0)
            <p class="text-sm text-green-700">All accounts match.</p>
        @else
            <p class="mb-2 text-sm text-red-700">{{ count($problems) }} mismatches found — investigate; do not credit anyone before fixing the data.</p>
            @foreach ($problems as $p)<div class="border-t py-1 text-sm"><a class="text-indigo-600" href="{{ route('admin.tenants.show', $p->tenant_id) }}">{{ $p->tenant_id }}</a> · {{ $p->check_name }} · Expected {{ $fmt($p->expected) }}, Actual {{ $fmt($p->actual) }}</div>@endforeach
        @endif
    </section>

    <section class="rounded-lg bg-white p-5 shadow">
        <h2 class="mb-2 font-medium">Credit packages</h2>
        <div class="overflow-x-auto"><table class="min-w-full text-sm">
            <thead class="text-left text-gray-600"><tr><th class="py-1 pr-2">Name</th><th class="pr-2">Credits</th><th class="pr-2">Bonus</th><th class="pr-2">Value</th><th class="pr-2">Validity (days)</th><th class="pr-2">Order</th><th class="pr-2">Status</th><th></th></tr></thead>
            <tbody>
            @foreach ($packages as $pk)
                <tr class="border-t align-top">
                    <td class="py-1 pr-2"><input form="pk-{{ $pk->id }}" name="name" value="{{ $pk->name }}" required class="w-36 rounded border border-gray-300 px-2 py-1" aria-label="Name"></td>
                    <td class="pr-2"><input form="pk-{{ $pk->id }}" name="credits" value="{{ \App\Credits\CreditMath::plain($pk->credits) }}" required class="w-24 rounded border border-gray-300 px-2 py-1" aria-label="Credits"></td>
                    <td class="pr-2"><input form="pk-{{ $pk->id }}" name="bonus_credits" value="{{ \App\Credits\CreditMath::plain($pk->bonus_credits) }}" class="w-20 rounded border border-gray-300 px-2 py-1" aria-label="Bonus"></td>
                    <td class="pr-2"><input form="pk-{{ $pk->id }}" name="price" value="{{ \App\Credits\CreditMath::plain($pk->price) }}" required class="w-24 rounded border border-gray-300 px-2 py-1" aria-label="Value"><input form="pk-{{ $pk->id }}" name="currency" value="{{ trim($pk->currency) }}" required maxlength="3" class="ml-1 w-14 rounded border border-gray-300 px-2 py-1" aria-label="Currency"></td>
                    <td class="pr-2"><input form="pk-{{ $pk->id }}" name="expires_after_days" type="number" min="1" value="{{ $pk->expires_after_days }}" class="w-20 rounded border border-gray-300 px-2 py-1" aria-label="Expiry"></td>
                    <td class="pr-2"><input form="pk-{{ $pk->id }}" name="sort" type="number" min="0" value="{{ $pk->sort }}" class="w-16 rounded border border-gray-300 px-2 py-1" aria-label="Order"></td>
                    <td class="pr-2"><select form="pk-{{ $pk->id }}" name="status" class="rounded border border-gray-300 px-2 py-1" aria-label="Status">@foreach (['active', 'hidden', 'retired'] as $st)<option value="{{ $st }}" @selected($pk->status === $st)>{{ $st }}</option>@endforeach</select></td>
                    <td><x-button form="pk-{{ $pk->id }}">Save</x-button></td></tr>
            @endforeach
            </tbody>
        </table></div>
        @foreach ($packages as $pk)<form method="POST" action="{{ route('admin.credits.packages.update', $pk->id) }}" id="pk-{{ $pk->id }}">@csrf @method('PUT')</form>@endforeach
        <form method="POST" action="{{ route('admin.credits.packages.store') }}" class="mt-4 grid max-w-3xl gap-3 sm:grid-cols-4">
            @csrf
            <input name="name" required maxlength="100" placeholder="Name" class="rounded-md border border-gray-300 px-3 py-2 text-sm" aria-label="Name">
            <input name="credits" required inputmode="decimal" placeholder="Credits" class="rounded-md border border-gray-300 px-3 py-2 text-sm" aria-label="Credits">
            <input name="bonus_credits" inputmode="decimal" placeholder="Bonus" class="rounded-md border border-gray-300 px-3 py-2 text-sm" aria-label="Bonus">
            <input name="price" required inputmode="decimal" placeholder="Value" class="rounded-md border border-gray-300 px-3 py-2 text-sm" aria-label="Value">
            <input name="currency" required maxlength="3" value="BDT" class="rounded-md border border-gray-300 px-3 py-2 text-sm" aria-label="Currency">
            <input name="expires_after_days" type="number" min="1" placeholder="Validity (days)" class="rounded-md border border-gray-300 px-3 py-2 text-sm" aria-label="Expiry">
            <input name="sort" type="number" min="0" placeholder="Order" class="rounded-md border border-gray-300 px-3 py-2 text-sm" aria-label="Order">
            <div><x-button>Add package</x-button></div>
        </form>
        <p class="mt-2 text-xs text-gray-500">New package "hidden" is created in a disabled state; the purchase feature (payments) comes in the next step.</p>
    </section>

    <section class="rounded-lg bg-white p-5 shadow">
        <h2 class="mb-2 font-medium">Provider Cost record (cannot be changed)</h2>
        @foreach ($costs as $c)<div class="flex flex-wrap justify-between border-t py-1 text-sm"><span>{{ $c->period }} · {{ $c->name }} · {{ $c->source }}@if ($c->notes) <span class="text-gray-500">({{ $c->notes }})</span>@endif</span><span class="tabular-nums">{{ rtrim(rtrim($c->amount, '0'), '.') }} {{ trim($c->currency) }}</span></div>@endforeach
        <form method="POST" action="{{ route('admin.credits.costs.store') }}" class="mt-3 grid max-w-3xl gap-3 sm:grid-cols-3">
            @csrf
            <select name="provider_id" required class="rounded-md border border-gray-300 px-3 py-2 text-sm" aria-label="Provider">@foreach ($providers as $pr)<option value="{{ $pr->id }}">{{ $pr->name }}</option>@endforeach</select>
            <input name="period" required placeholder="YYYY-MM" pattern="\d{4}-(0[1-9]|1[0-2])" class="rounded-md border border-gray-300 px-3 py-2 text-sm" aria-label="month(s)">
            <input name="amount" required inputmode="decimal" placeholder="Quantity" class="rounded-md border border-gray-300 px-3 py-2 text-sm" aria-label="Quantity">
            <input name="currency" required maxlength="3" value="USD" class="rounded-md border border-gray-300 px-3 py-2 text-sm" aria-label="Currency">
            <select name="source" class="rounded-md border border-gray-300 px-3 py-2 text-sm" aria-label="Source"><option value="invoice">invoice</option><option value="computed">computed</option></select>
            <input name="notes" maxlength="500" placeholder="Note" class="rounded-md border border-gray-300 px-3 py-2 text-sm" aria-label="Note">
            <div><x-button>Record</x-button></div>
        </form>
    </section>
</x-layouts.admin>
