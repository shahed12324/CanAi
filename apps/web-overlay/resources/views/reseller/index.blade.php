<x-layouts.app title="Reseller">
    {{-- JARVIS ONE: page-specific visual identity; existing actions preserved --}}
    <header class="jarvis-module-hero jarvis-accent-violet" data-jarvis-module="reseller" aria-label="Reseller">
      <div class="jarvis-module-hero__identity"><span class="jarvis-module-hero__glyph" aria-hidden="true">✦</span><div>
        <div class="jarvis-module-hero__eyebrow">JARVIS ONE <span aria-hidden="true">/</span> RESELLER</div>
        <h1>Reseller</h1>
      </div></div>
      <span class="jarvis-module-hero__section">Reseller Workspace</span>
    </header>

    <h2 class="text-xl font-semibold">Reseller</h2>
    @if ($errors->has('code'))<p class="mt-2 rounded bg-red-50 p-3 text-sm text-red-700">{{ $errors->first('code') }}</p>@endif
    @if (session('status'))<p class="mt-2 rounded bg-green-50 p-3 text-sm text-green-700">{{ session('status') }}</p>@endif

    <section class="mt-3 rounded-lg bg-white p-4 text-sm shadow">
        <h3 class="font-semibold">Reseller of your workspace</h3>
        @if ($joined !== null)
            <p class="mt-1">You are <b>{{ $joined }}</b>-linked with. A reseller sees only your workspace’s name, the join date and the number of paid orders — none of your data or content.</p>
        @else
            <p class="mt-1 text-xs text-gray-600">If a reseller gave you a code, enter it here. Only the workspace owner can, only once, and it cannot be changed. It does not raise your price.</p>
            <form method="POST" action="{{ route('reseller.join') }}" class="mt-2 flex gap-2">@csrf
                <input name="code" maxlength="40" required autocomplete="off" class="rounded border px-2 py-1" placeholder="Reseller code">
                <button class="rounded bg-indigo-600 px-3 py-1 text-white">Join</button></form>
        @endif
    </section>

    @if ($me !== null && $report !== null)
    <section class="mt-3 rounded-lg bg-white p-4 text-sm shadow">
        <h3 class="font-semibold">Reseller panel — {{ $me['name'] }}</h3>
        <p class="mt-1">Your code: <code class="rounded bg-gray-100 px-2 py-0.5 text-base">{{ $me['code'] }}</code> · Rate: {{ rtrim(rtrim(number_format($me['commission_bp'] / 100, 2), '0'), '.') }}% (for new customers) @if ($me['status'] !== 'active')· <span class="text-red-700">Suspended</span>@endif</p>
        <p class="mt-1 text-xs text-gray-600">Commission = on paid orders (excluding VAT and refunds) after the customer joins. The platform admin records payouts.</p>
        <table class="mt-2 w-full text-left text-xs"><thead><tr><th>Currency</th><th>Income</th><th>Commission</th><th>Paid</th><th>Receivable</th></tr></thead><tbody>
            @forelse ($report['money'] as $m)<tr><td>{{ $m['currency'] }}</td><td>{{ $m['revenue'] }}</td><td>{{ $m['commission'] }}</td><td>{{ $m['paid_out'] }}</td><td><b>{{ $m['due'] }}</b></td></tr>
            @empty<tr><td colspan="5" class="text-gray-500">No paid orders yet.</td></tr>@endforelse</tbody></table>
        <h4 class="mt-3 font-semibold">Customers ({{ count($report['customers']) }})</h4>
        <ul class="text-xs">@forelse ($report['customers'] as $c)<li>· {{ $c['name'] }} — {{ \Illuminate\Support\Str::of($c['joined_at'])->substr(0, 10) }} · Paid orders {{ $c['paid_orders'] }}</li>@empty<li class="text-gray-500">No one has joined yet.</li>@endforelse</ul>
    </section>
    @endif
</x-layouts.app>
