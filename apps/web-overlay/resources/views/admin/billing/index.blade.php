<x-layouts.admin title="Billing and revenue">
    {{-- JARVIS ONE: page-specific visual identity; existing actions preserved --}}
    <header class="jarvis-module-hero jarvis-accent-violet" data-jarvis-module="admin" aria-label="Billing and revenue">
      <div class="jarvis-module-hero__identity"><span class="jarvis-module-hero__glyph" aria-hidden="true">✦</span><div>
        <div class="jarvis-module-hero__eyebrow">JARVIS ONE <span aria-hidden="true">/</span> ADMIN</div>
        <h1>Billing and revenue</h1><p>Manage your platform, permissions and operations.</p>
      </div></div>
      <span class="jarvis-module-hero__section">Admin Workspace</span>
    </header>

    @php($P = \App\Billing\Present::class)
    <h1 class="text-xl font-semibold">Billing and revenue</h1>
    @if (session('status'))<p class="rounded bg-green-50 p-3 text-sm text-green-700">{{ session('status') }}</p>@endif
    @if ($errors->any())<div class="rounded bg-red-50 p-3 text-sm text-red-700">@foreach ($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div>@endif

    <div class="grid gap-4 sm:grid-cols-4">
        <div class="rounded-lg bg-white p-4 shadow"><p class="text-xs text-gray-500">MRR (BDT)</p><p class="text-2xl font-semibold tabular-nums">{{ $P::money((int) $mrr->mrr_minor, 'BDT') }}</p><p class="text-xs text-gray-500">{{ $mrr->subscribers }} Active paid subscriptions</p></div>
        <div class="rounded-lg bg-white p-4 shadow"><p class="text-xs text-gray-500">Live subscriptions</p><p class="text-2xl font-semibold tabular-nums">{{ $churn->live }}</p></div>
        <div class="rounded-lg bg-white p-4 shadow"><p class="text-xs text-gray-500">Cancelled in last 30 days</p><p class="text-2xl font-semibold tabular-nums">{{ $churn->cancelled }}</p></div>
        <div class="rounded-lg bg-white p-4 shadow"><p class="text-xs text-gray-500">Expired in last 30 days</p><p class="text-2xl font-semibold tabular-nums">{{ $churn->expired }}</p></div>
    </div>

    <section class="rounded-lg bg-white p-5 shadow">
        <h2 class="mb-2 font-medium">Gateway status</h2>
        <p class="text-sm">On: @forelse ($configured as $c)<span class="mr-1 rounded bg-green-50 px-2 py-0.5 text-green-700">{{ $c }}</span>@empty<span class="text-amber-700">None — set the key in .env</span>@endforelse</p>
        @if (empty($settings['seller']['name'] ?? ''))<p class="mt-2 rounded bg-amber-50 p-2 text-sm text-amber-800">The invoice seller details (name, address, BIN) are blank. Fill in "Billing settings" below, otherwise the seller name will not appear on invoices.</p>@endif
        <table class="mt-3 w-full text-sm"><thead class="text-left text-gray-500"><tr><th>Gateway (30 days)</th><th>Order</th><th>Paid</th><th>Failed/cancelled</th><th>Verify</th><th>Total</th></tr></thead><tbody>
            @foreach ($gatewayStats as $g)<tr class="border-t"><td class="py-1">{{ $g->gateway }}</td><td>{{ $g->orders }}</td><td>{{ $g->paid }}</td><td>{{ $g->failed }}</td><td>{{ $g->review }}</td><td class="tabular-nums">{{ $P::money((int) $g->gross_minor, 'BDT') }}</td></tr>@endforeach
        </tbody></table>
    </section>

    <section class="rounded-lg bg-white p-5 shadow">
        <h2 class="mb-2 font-medium">Billing settings</h2>
        <form method="POST" action="{{ route('admin.billing.settings') }}" class="grid gap-2 sm:grid-cols-3">@csrf @method('PUT')
            <label class="text-sm">VAT % (BDT orders only)<input name="vat_percent" value="{{ $settings['vat_bp'] / 100 }}" class="w-full rounded border px-2 py-1" required></label>
            <label class="text-sm">Grace period (days)<input name="grace_days" type="number" value="{{ $settings['grace_days'] }}" class="w-full rounded border px-2 py-1" required></label>
            <label class="text-sm">Order expiry (minutes)<input name="order_ttl_minutes" type="number" value="{{ $settings['order_ttl_minutes'] }}" class="w-full rounded border px-2 py-1" required></label>
            <label class="text-sm">Seller name (on invoice)<input name="seller_name" value="{{ $settings['seller']['name'] ?? '' }}" class="w-full rounded border px-2 py-1"></label>
            <label class="text-sm">Address<input name="seller_address" value="{{ $settings['seller']['address'] ?? '' }}" class="w-full rounded border px-2 py-1"></label>
            <label class="text-sm">BIN<input name="seller_bin" value="{{ $settings['seller']['bin'] ?? '' }}" class="w-full rounded border px-2 py-1"></label>
            <label class="text-sm">Email<input name="seller_email" type="email" value="{{ $settings['seller']['email'] ?? '' }}" class="w-full rounded border px-2 py-1"></label>
            <div class="flex items-end"><x-button>Save</x-button></div>
        </form>
    </section>

    <div class="grid gap-4 md:grid-cols-2">
        <section class="rounded-lg bg-white p-5 shadow"><h2 class="mb-2 font-medium">Monthly revenue (Dhaka time)</h2>
            <table class="w-full text-sm"><thead class="text-left text-gray-500"><tr><th> month(s)</th><th>Currency</th><th>Order</th><th>Total</th><th>VAT</th><th> return</th></tr></thead><tbody>
                @foreach ($revenue as $r)<tr class="border-t"><td class="py-1">{{ $r->month }}</td><td>{{ trim($r->currency) }}</td><td>{{ $r->orders }}</td><td class="tabular-nums">{{ $P::money((int) $r->gross_minor, $r->currency) }}</td><td class="tabular-nums">{{ $P::money((int) $r->vat_minor, $r->currency) }}</td><td class="tabular-nums">{{ $P::money((int) $r->refunded_minor, $r->currency) }}</td></tr>@endforeach
            </tbody></table></section>
        <section class="rounded-lg bg-white p-5 shadow"><h2 class="mb-2 font-medium">Customers by plan</h2>
            <table class="w-full text-sm"><tbody>@foreach ($byPlan as $r)<tr class="border-t"><td class="py-1">{{ $r->name }}</td><td>{{ $P::SUB_STATUS[$r->status] ?? $r->status }}</td><td class="text-right">{{ $r->n }}</td></tr>@endforeach</tbody></table></section>
    </div>

    @if (count($review))
        <section class="rounded-lg border border-amber-300 bg-amber-50 p-5">
            <h2 class="mb-2 font-medium text-amber-900">Awaiting verification ({{ count($review) }})</h2>
            <p class="mb-2 text-sm text-amber-900">Amount/currency did not match. Check the money in the gateway dashboard and either refund it or match it to the correct order and close the order.</p>
            @foreach ($review as $o)
                <div class="flex flex-wrap items-center gap-3 border-t border-amber-200 py-2 text-sm">
                    <span class="font-mono text-xs">{{ trim($o->id) }}</span><span>{{ $o->tenant }}</span><span>{{ $o->item_name }}</span>
                    <span>Due {{ $P::money((int) $o->total_minor, $o->currency) }}</span>
                    <span>Arrived {{ $o->received_minor !== null ? $P::money((int) $o->received_minor, $o->received_currency ?? $o->currency) : '—' }}</span><span>{{ $o->gateway }}</span>
                    <form method="POST" action="{{ route('admin.billing.close_review', trim($o->id)) }}" class="ml-auto">@csrf<x-button variant="ghost">Deactivate</x-button></form>
                </div>
            @endforeach
        </section>
    @endif

    <section class="rounded-lg bg-white p-5 shadow">
        <div class="mb-2 flex flex-wrap items-center gap-2"><h2 class="font-medium">Order</h2>
            @foreach (array_merge([''], $statuses) as $s)<a class="rounded px-2 py-0.5 text-xs {{ $s === $status ? 'bg-indigo-600 text-white' : 'bg-gray-100' }}" href="{{ route('admin.billing.index', $s === '' ? [] : ['status' => $s]) }}">{{ $s === '' ? 'All' : ($P::ORDER_STATUS[$s] ?? $s) }}</a>@endforeach</div>
        <div class="overflow-x-auto"><table class="w-full text-sm"><thead class="text-left text-gray-500"><tr><th>Workspace</th><th>Item</th><th>Amount</th><th>Gateway</th><th>Status</th><th>Date</th><th> return</th></tr></thead><tbody>
            @foreach ($orders as $o)
                <tr class="border-t align-top"><td class="py-1">{{ $o->tenant }}</td><td>{{ $o->item_name }}</td><td class="tabular-nums">{{ $P::money((int) $o->total_minor, $o->currency) }}</td><td>{{ $o->gateway }}</td><td>{{ $P::ORDER_STATUS[$o->status] ?? $o->status }}</td><td>{{ \Illuminate\Support\Carbon::parse($o->created_at)->timezone('Asia/Dhaka')->format('d M, h:i A') }}</td>
                    <td>@if (in_array($o->status, ['paid', 'partially_refunded'], true))
                        <details><summary class="cursor-pointer text-indigo-600">Refund recorded</summary>
                            <form method="POST" action="{{ route('admin.billing.refund', trim($o->id)) }}" class="mt-1 w-64 space-y-1">@csrf
                                <input name="amount" placeholder="Amount (max {{ \App\Billing\Amount::toDecimal((int) $o->total_minor - (int) $o->refunded_minor) }})" class="w-full rounded border px-2 py-1" required>
                                <input name="reason" placeholder="Reason" class="w-full rounded border px-2 py-1" required>
                                <input name="gateway_ref" placeholder="Gateway refund reference (optional)" class="w-full rounded border px-2 py-1">
                                <label class="flex gap-1 text-xs"><input type="checkbox" name="claw_back" value="1"> Take back purchased credits</label>
                                <label class="flex gap-1 text-xs"><input type="checkbox" name="cancel_subscription" value="1"> Cancel subscription</label>
                                <x-button variant="danger">Record</x-button>
                            </form></details>
                    @endif</td></tr>
            @endforeach
        </tbody></table></div>
    </section>

    @if (count($refunds))
        <section class="rounded-lg bg-white p-5 shadow"><h2 class="mb-2 font-medium">Recent refunds</h2>
            <table class="w-full text-sm"><tbody>@foreach ($refunds as $r)<tr class="border-t"><td class="py-1">{{ $r->tenant }}</td><td class="tabular-nums">{{ $P::money((int) $r->amount_minor, $r->currency) }}</td><td>{{ $r->reason }}</td><td>{{ \Illuminate\Support\Carbon::parse($r->created_at)->timezone('Asia/Dhaka')->format('d M Y') }}</td></tr>@endforeach</tbody></table></section>
    @endif

    <section class="space-y-3 rounded-lg bg-white p-5 shadow">
        <h2 class="font-medium">Coupon</h2>
        <form method="POST" action="{{ route('admin.billing.coupons.store') }}" class="grid gap-2 sm:grid-cols-4">@csrf
            <input name="code" placeholder="Code (for example EID25)" class="rounded border px-2 py-1 uppercase" required maxlength="32">
            <select name="kind" class="rounded border px-2 py-1"><option value="percent">Percent %</option><option value="fixed">Fixed amount</option></select>
            <input name="percent" placeholder="Percent (for example 20)" class="rounded border px-2 py-1">
            <span class="flex gap-1"><input name="fixed" placeholder="Fixed amount" class="w-full rounded border px-2 py-1"><input name="currency" placeholder="BDT" maxlength="3" class="w-16 rounded border px-2 py-1 uppercase"></span>
            <select name="applies_to" class="rounded border px-2 py-1"><option value="any">On everything</option><option value="subscription">Subscriptions only</option><option value="credits">Credits only</option></select>
            <input name="max_redemptions" type="number" min="1" placeholder="Total usage limit" class="rounded border px-2 py-1">
            <input name="per_tenant_limit" type="number" min="1" value="1" class="rounded border px-2 py-1" title="Max uses per workspace">
            <input name="description" placeholder="Note" class="rounded border px-2 py-1">
            <input name="valid_from" type="datetime-local" class="rounded border px-2 py-1" title="Start"><input name="valid_until" type="datetime-local" class="rounded border px-2 py-1" title="End">
            <x-button>Add coupon</x-button>
        </form>
        <table class="w-full text-sm"><thead class="text-left text-gray-500"><tr><th>Code</th><th>Discount</th><th>Applies to</th><th>Uses</th><th>Expiry</th><th>Status</th><th></th></tr></thead><tbody>
            @foreach ($coupons as $c)
                <tr class="border-t"><td class="py-1 font-mono">{{ $c->code }}</td>
                    <td>{{ $c->kind === 'percent' ? $P::bp((int) $c->percent_bp) : $P::money((int) $c->fixed_minor, trim($c->currency)) }}</td><td>{{ $c->applies_to }}</td>
                    <td>{{ $c->redeemed_count }}@if ($c->max_redemptions) / {{ $c->max_redemptions }}@endif</td>
                    <td class="text-xs text-gray-600">{{ $c->valid_from ? \Illuminate\Support\Carbon::parse($c->valid_from)->format('d M Y') : '—' }} → {{ $c->valid_until ? \Illuminate\Support\Carbon::parse($c->valid_until)->format('d M Y') : '—' }}</td><td>{{ $c->status }}</td>
                    <td><form method="POST" action="{{ route('admin.billing.coupons.status', trim($c->id)) }}">@csrf<input type="hidden" name="status" value="{{ $c->status === 'active' ? 'disabled' : 'active' }}"><button class="text-indigo-600">{{ $c->status === 'active' ? 'Closed' : 'Active' }}</button></form></td></tr>
            @endforeach
        </tbody></table>
    </section>
</x-layouts.admin>
