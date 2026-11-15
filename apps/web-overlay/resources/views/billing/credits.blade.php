<x-layouts.app title="Buy credits">
    {{-- JARVIS ONE: page-specific visual identity; existing actions preserved --}}
    <header class="jarvis-module-hero jarvis-accent-violet" data-jarvis-module="billing" aria-label="Buy credits">
      <div class="jarvis-module-hero__identity"><span class="jarvis-module-hero__glyph" aria-hidden="true">✦</span><div>
        <div class="jarvis-module-hero__eyebrow">JARVIS ONE <span aria-hidden="true">/</span> BILLING</div>
        <h1>Buy credits</h1><p>Manage subscriptions, invoices and payment history.</p>
      </div></div>
      <span class="jarvis-module-hero__section">Billing Workspace</span>
    </header>

    @php($P = \App\Billing\Present::class)
    <div class="flex items-center gap-3"><h1 class="text-xl font-semibold">Credit packages</h1><a class="ml-auto text-sm text-indigo-600" href="{{ route('billing.index') }}">← Billing</a></div>
    @include('billing._flash')
    <div class="grid gap-4 md:grid-cols-3">
        @forelse ($packages as $p)
            <section class="space-y-2 rounded-lg bg-white p-5 shadow">
                <h3 class="font-semibold">{{ $p->name }}</h3>
                <p class="text-2xl font-semibold tabular-nums">{{ \App\Credits\CreditMath::format($p->credits) }} Credits</p>
                @if ((float) $p->bonus_credits > 0)<p class="text-sm text-green-700">+ Bonus {{ \App\Credits\CreditMath::format($p->bonus_credits) }}</p>@endif
                @if ($p->expires_after_days)<p class="text-xs text-gray-500">{{ $p->expires_after_days }} must be used within days</p>@endif
                <p>{{ $P::money((int) round(((float) $p->price) * 100), $p->currency) }}@if ($currency === 'BDT') <span class="text-xs text-gray-500">+ VAT</span>@endif</p>
                @if ($canManage)<a class="inline-block rounded bg-indigo-600 px-3 py-1 text-sm text-white" href="{{ route('billing.checkout', ['kind' => 'credits', 'ref' => trim($p->id), 'currency' => $currency]) }}">Buy</a>@endif
            </section>
        @empty
            <p class="text-gray-600">No packages now.</p>
        @endforelse
    </div>
</x-layouts.app>
