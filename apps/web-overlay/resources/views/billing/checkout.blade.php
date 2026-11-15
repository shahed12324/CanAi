<x-layouts.app title="Payment">
    {{-- JARVIS ONE: page-specific visual identity; existing actions preserved --}}
    <header class="jarvis-module-hero jarvis-accent-violet" data-jarvis-module="billing" aria-label="Payment">
      <div class="jarvis-module-hero__identity"><span class="jarvis-module-hero__glyph" aria-hidden="true">✦</span><div>
        <div class="jarvis-module-hero__eyebrow">JARVIS ONE <span aria-hidden="true">/</span> BILLING</div>
        <h1>Payment</h1><p>Manage subscriptions, invoices and payment history.</p>
      </div></div>
      <span class="jarvis-module-hero__section">Billing Workspace</span>
    </header>

    @php($P = \App\Billing\Present::class)
    <h1 class="text-xl font-semibold">Confirm order</h1>
    @include('billing._flash')
    @if ($error)<p class="rounded bg-red-50 p-3 text-sm text-red-700">{{ $error }}</p>@endif

    @if ($quote)
        @php($q = (object) $quote)
        @php($cur = trim($q->currency))
        <section class="max-w-lg space-y-3 rounded-lg bg-white p-5 shadow">
            <h2 class="font-medium">{{ $q->item_name }}@if ($q->change_kind === 'renew') <span class="text-sm text-gray-500">(Renewal)</span>@elseif ($q->change_kind === 'upgrade') <span class="text-sm text-gray-500">(Upgrade)</span>@endif</h2>
            <table class="w-full text-sm">
                <tr><td class="py-1">Value</td><td class="text-right tabular-nums">{{ $P::money((int) $q->list_minor, $cur) }}</td></tr>
                @if ($q->proration_minor > 0)<tr><td class="py-1">Credit for unused time on the previous plan</td><td class="text-right tabular-nums">− {{ $P::money((int) $q->proration_minor, $cur) }}</td></tr>@endif
                @if ($q->discount_minor > 0)<tr><td class="py-1">Coupon {{ $q->coupon_code }}</td><td class="text-right tabular-nums">− {{ $P::money((int) $q->discount_minor, $cur) }}</td></tr>@endif
                @if ($q->vat_bp > 0)<tr><td class="py-1">VAT ({{ $P::bp((int) $q->vat_bp) }})</td><td class="text-right tabular-nums">{{ $P::money((int) $q->vat_minor, $cur) }}</td></tr>@endif
                <tr class="border-t font-semibold"><td class="py-2">Total</td><td class="text-right tabular-nums">{{ $P::money((int) $q->total_minor, $cur) }}</td></tr>
            </table>

            <form method="GET" action="{{ route('billing.checkout') }}" class="flex gap-2">
                <input type="hidden" name="kind" value="{{ $data['kind'] }}"><input type="hidden" name="ref" value="{{ $data['ref'] }}"><input type="hidden" name="interval" value="{{ $data['interval'] }}"><input type="hidden" name="currency" value="{{ $data['currency'] }}">
                <input name="coupon" value="{{ $data['coupon'] }}" placeholder="Coupon code" class="w-full rounded-md border border-gray-300 px-3 py-2 uppercase" maxlength="32">
                <x-button>Apply</x-button>
            </form>

            <form method="POST" action="{{ route('billing.pay') }}" class="space-y-3">
                @csrf
                <input type="hidden" name="kind" value="{{ $data['kind'] }}"><input type="hidden" name="ref" value="{{ $data['ref'] }}"><input type="hidden" name="interval" value="{{ $data['interval'] }}"><input type="hidden" name="currency" value="{{ $data['currency'] }}"><input type="hidden" name="coupon" value="{{ $data['coupon'] }}">
                @if ((int) $q->total_minor === 0)
                    <input type="hidden" name="gateway" value="free">
                    <p class="text-sm text-green-700">The coupon covers the full amount — no payment is needed.</p>
                    <x-button>Confirm</x-button>
                @elseif (count($gateways))
                    <fieldset class="space-y-1"><legend class="text-sm font-medium text-gray-700">Payment method</legend>
                        @foreach ($gateways as $i => $g)
                            <label class="flex items-center gap-2 text-sm"><input type="radio" name="gateway" value="{{ $g->code() }}" @checked($i === 0) required> {{ $g->label() }}</label>
                        @endforeach
                    </fieldset>
                    <x-button>Pay</x-button>
                @else
                    <p class="text-sm text-amber-800">No payment gateway is enabled for this currency.</p>
                @endif
            </form>
            <p class="text-xs text-gray-500">Payment is completed on the payment gateway’s secure page; card or mobile wallet details never reach our servers. The amount and coupon are verified on the server.</p>
        </section>
    @endif
    <a class="text-sm text-indigo-600" href="{{ route('billing.index') }}">← Go back</a>
</x-layouts.app>
