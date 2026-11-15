<x-layouts.app title="Demo payment">
    {{-- JARVIS ONE: page-specific visual identity; existing actions preserved --}}
    <header class="jarvis-module-hero jarvis-accent-violet" data-jarvis-module="billing" aria-label="Demo payment">
      <div class="jarvis-module-hero__identity"><span class="jarvis-module-hero__glyph" aria-hidden="true">✦</span><div>
        <div class="jarvis-module-hero__eyebrow">JARVIS ONE <span aria-hidden="true">/</span> BILLING</div>
        <h1>Demo payment</h1><p>Manage subscriptions, invoices and payment history.</p>
      </div></div>
      <span class="jarvis-module-hero__section">Billing Workspace</span>
    </header>

    <section class="mx-auto max-w-md space-y-3 rounded-lg bg-white p-6 shadow">
        <p class="rounded bg-amber-50 p-2 text-sm text-amber-800">Demo gateway — no real money is charged.</p>
        <h1 class="text-lg font-semibold">{{ $o->item_name }}</h1>
        <p class="text-2xl tabular-nums">{{ \App\Billing\Present::money((int) $o->total_minor, trim($o->currency)) }}</p>
        <div class="flex gap-2">
            @foreach ([['paid', $paySig, 'Mark payment successful', 'bg-indigo-600 text-white'], ['fail', $failSig, 'Mark failed', 'bg-gray-200']] as [$result, $sig, $label, $cls])
                <form method="POST" action="{{ route('billing.return', 'demo') }}">
                    <input type="hidden" name="ref" value="{{ $ref }}"><input type="hidden" name="result" value="{{ $result }}"><input type="hidden" name="sig" value="{{ $sig }}">
                    <button class="rounded px-4 py-2 text-sm {{ $cls }}">{{ $label }}</button>
                </form>
            @endforeach
        </div>
    </section>
</x-layouts.app>
