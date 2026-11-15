<x-layouts.app title="Billing">
    {{-- JARVIS ONE: page-specific visual identity; existing actions preserved --}}
    <header class="jarvis-module-hero jarvis-accent-violet" data-jarvis-module="billing" aria-label="Billing">
      <div class="jarvis-module-hero__identity"><span class="jarvis-module-hero__glyph" aria-hidden="true">✦</span><div>
        <div class="jarvis-module-hero__eyebrow">JARVIS ONE <span aria-hidden="true">/</span> BILLING</div>
        <h1>Billing</h1><p>Manage subscriptions, invoices and payment history.</p>
      </div></div>
      <span class="jarvis-module-hero__section">Billing Workspace</span>
    </header>

    @php($P = \App\Billing\Present::class)
    <div class="flex flex-wrap items-center gap-3">
        <h1 class="text-xl font-semibold">Billing and subscription</h1>
        <nav class="ml-auto flex gap-3 text-sm text-indigo-600">
            <a href="{{ route('billing.credits') }}">Buy credits</a><a href="{{ route('billing.orders') }}">Order</a><a href="{{ route('billing.invoices') }}">Invoices</a><a href="{{ route('billing.profile') }}">Billing information</a>
        </nav>
    </div>

    @if ($result && isset($P::RESULT[$result]))
        <p class="rounded p-3 text-sm {{ $P::RESULT[$result][0] === 'ok' ? 'bg-green-50 text-green-700' : 'bg-amber-50 text-amber-800' }}">{{ $P::RESULT[$result][1] }}</p>
    @endif
    @include('billing._flash')
    @if (config('billing.demo'))<p class="rounded bg-amber-50 p-3 text-sm text-amber-800">Demo mode on: "Demo payment" no real money is charged.</p>@endif

    <section class="rounded-lg bg-white p-5 shadow">
        <h2 class="font-medium">Current plan</h2>
        @if ($sub)
            <p class="mt-2 text-lg"><strong>{{ $sub->plan_name }}</strong> · {{ $P::INTERVAL[$sub->interval] ?? '' }} · <span class="rounded bg-gray-100 px-2 py-0.5 text-sm">{{ $P::SUB_STATUS[$sub->status] ?? $sub->status }}</span></p>
            @if ($sub->current_period_end)<p class="text-sm text-gray-600">Expires: {{ \Illuminate\Support\Carbon::parse($sub->current_period_end)->timezone('Asia/Dhaka')->format('d M Y, h:i A') }}</p>@endif
            @if ($sub->status === 'grace')<p class="mt-1 rounded bg-amber-50 p-2 text-sm text-amber-800">Has expired. {{ \Illuminate\Support\Carbon::parse($sub->grace_ends_at)->timezone('Asia/Dhaka')->format('d M Y') }} Benefits remain active until — renew before then.</p>@endif
            @if ($sub->cancel_at && $sub->status === 'active')<p class="mt-1 rounded bg-amber-50 p-2 text-sm text-amber-800">Will be cancelled at the end of this period.</p>@endif
            @if ($sub->pending_name)<p class="mt-1 rounded bg-blue-50 p-2 text-sm text-blue-800">At the end of the term {{ $sub->pending_name }} ({{ $P::INTERVAL[$sub->pending_interval] ?? '' }}) will move to the plan.</p>@endif
            @if ($canManage)
                <div class="mt-3 flex flex-wrap gap-2 text-sm">
                    @if ($sub->cancel_at && $sub->status === 'active')
                        <form method="POST" action="{{ route('billing.resume') }}">@csrf<x-button>Withdraw cancellation</x-button></form>
                    @elseif (in_array($sub->status, ['active', 'trialing'], true))
                        <form method="POST" action="{{ route('billing.cancel') }}" onsubmit="return confirm('Cancel at the end of the term?')">@csrf<x-button>Cancel at end of term</x-button></form>
                    @endif
                    @if ($sub->pending_name)
                        <form method="POST" action="{{ route('billing.downgrade.clear') }}">@csrf<x-button>Cancel downgrade</x-button></form>
                    @endif
                </div>
            @endif
        @else
            <p class="mt-2 text-gray-600">You are now on the Free plan.</p>
        @endif
    </section>

    <div class="flex items-center gap-2 text-sm">
        <span class="text-gray-600">Currency:</span>
        @foreach ($currencies as $c)<a href="{{ route('billing.index', ['currency' => $c]) }}" class="rounded px-2 py-1 {{ $c === $currency ? 'bg-indigo-600 text-white' : 'bg-white shadow' }}">{{ $c }}</a>@endforeach
    </div>

    @unless ($gatewaysReady)<p class="rounded bg-amber-50 p-3 text-sm text-amber-800">No payment gateway is enabled. Purchases become possible when the admin sets the gateway key in .env.</p>@endunless

    <div class="grid gap-4 md:grid-cols-3">
        @foreach ($plans as $p)
            @php($pid = trim($p->id))
            @php($m = $prices[$pid]['month'] ?? null)
            @php($y = $prices[$pid]['year'] ?? null)
            <section class="space-y-2 rounded-lg bg-white p-5 shadow {{ $sub && trim($sub->plan_id) === $pid ? 'ring-2 ring-indigo-500' : '' }}">
                <h3 class="text-lg font-semibold">{{ $p->name }}</h3>
                @if ($p->description)<p class="text-sm text-gray-600">{{ $p->description }}</p>@endif
                @if ((float) $p->included_credits > 0)<p class="text-sm">Per term {{ \App\Credits\CreditMath::format($p->included_credits) }} Credits</p>@endif
                @if ($m === null && $y === null)
                    <p class="text-sm text-gray-500">No price is set in this currency.</p>
                @endif
                @foreach (['month' => $m, 'year' => $y] as $iv => $amt)
                    @if ($amt !== null)
                        <div class="flex items-center justify-between gap-2 border-t pt-2 text-sm">
                            <span>{{ $P::INTERVAL[$iv] }} <strong>{{ $P::money($amt, $currency) }}</strong>@if ($currency === 'BDT') <span class="text-xs text-gray-500">+ VAT</span>@endif</span>
                            @if ($canManage)
                                @php($isDown = $sub && in_array($sub->status, ['active', 'trialing'], true) && ($p->sort < $sub->sort || (trim($sub->plan_id) === $pid && $sub->interval === 'year' && $iv === 'month')))
                                @if ($isDown)
                                    <form method="POST" action="{{ route('billing.downgrade') }}">@csrf<input type="hidden" name="plan" value="{{ $pid }}"><input type="hidden" name="interval" value="{{ $iv }}"><x-button>Downgrade at end of term</x-button></form>
                                @else
                                    <a class="rounded bg-indigo-600 px-3 py-1 text-white" href="{{ route('billing.checkout', ['kind' => 'subscription', 'ref' => $pid, 'interval' => $iv, 'currency' => $currency]) }}">{{ $sub && trim($sub->plan_id) === $pid && $sub->interval === $iv ? 'Renew' : 'Buy' }}</a>
                                @endif
                            @endif
                        </div>
                    @endif
                @endforeach
            </section>
        @endforeach
    </div>

    @if (count($orders))
        <section class="rounded-lg bg-white p-5 shadow">
            <h2 class="mb-2 font-medium">Recent orders</h2>
            <table class="w-full text-sm"><tbody>
                @foreach ($orders as $o)
                    <tr class="border-t"><td class="py-1">{{ $o->item_name }}</td><td class="tabular-nums">{{ $P::money((int) $o->total_minor, $o->currency) }}</td><td>{{ $P::ORDER_STATUS[$o->status] ?? $o->status }}</td><td class="text-gray-500">{{ \Illuminate\Support\Carbon::parse($o->created_at)->timezone('Asia/Dhaka')->format('d M, h:i A') }}</td></tr>
                @endforeach
            </tbody></table>
        </section>
    @endif
</x-layouts.app>
