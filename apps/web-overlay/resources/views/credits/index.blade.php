<x-layouts.app title="Credits">
    {{-- JARVIS ONE: page-specific visual identity; existing actions preserved --}}
    <header class="jarvis-module-hero jarvis-accent-violet" data-jarvis-module="credits" aria-label="Credits">
      <div class="jarvis-module-hero__identity"><span class="jarvis-module-hero__glyph" aria-hidden="true">✦</span><div>
        <div class="jarvis-module-hero__eyebrow">JARVIS ONE <span aria-hidden="true">/</span> CREDITS</div>
        <h1>Credits</h1>
      </div></div>
      <span class="jarvis-module-hero__section">Credits Workspace</span>
    </header>

    @php($fmt = fn ($v) => \App\Credits\CreditMath::format($v))
    <h1 class="text-xl font-semibold">Credits and usage</h1>

    @if (session('status'))<p class="rounded bg-green-50 p-3 text-sm text-green-700">{{ session('status') }}</p>@endif
    @if ($errors->any())<div class="rounded bg-red-50 p-3 text-sm text-red-700">@foreach ($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div>@endif

    <div class="grid gap-4 sm:grid-cols-4">
        <div class="rounded-lg bg-white p-4 shadow"><p class="text-xs text-gray-500">Usable credits</p><p class="text-2xl font-semibold tabular-nums">{{ $fmt($wallet?->available ?? 0) }}</p></div>
        <div class="rounded-lg bg-white p-4 shadow"><p class="text-xs text-gray-500">Total balance</p><p class="text-2xl font-semibold tabular-nums">{{ $fmt($wallet?->balance ?? 0) }}</p></div>
        <div class="rounded-lg bg-white p-4 shadow"><p class="text-xs text-gray-500">Held for in-progress requests</p><p class="text-2xl font-semibold tabular-nums">{{ $fmt($wallet?->reserved ?? 0) }}</p></div>
        <div class="rounded-lg bg-white p-4 shadow"><p class="text-xs text-gray-500">Spent this month @if ($wallet?->spending_limit_monthly !== null) / Limit {{ $fmt($wallet->spending_limit_monthly) }}@endif</p><p class="text-2xl font-semibold tabular-nums">{{ $fmt($spent) }}</p></div>
    </div>

    <div class="flex flex-wrap items-center gap-2 text-sm">
        <span class="text-gray-600">Period:</span>
        @foreach ($periods as $p)
            <a href="{{ route('credits.index', ['days' => $p]) }}" class="rounded px-2 py-1 {{ $p === $days ? 'bg-indigo-600 text-white' : 'bg-white text-gray-700 shadow' }}">{{ $p }} days</a>
        @endforeach
        <span class="ml-auto text-gray-600">Total usage in this period: <strong>{{ $fmt($totalPeriod) }}</strong></span>
        <a class="text-indigo-600" href="{{ route('credits.transactions') }}">Transaction history →</a>
    </div>

    <div class="grid gap-4 md:grid-cols-2">
        @include('credits._bars', ['title' => 'By day', 'rows' => $daily])
        @include('credits._bars', ['title' => 'By model', 'rows' => $byModel])
        @include('credits._bars', ['title' => 'By agent', 'rows' => $byAgent])
        @include('credits._bars', ['title' => 'By category', 'rows' => $byDepartment])
    </div>

    @if ($canManage)
        <section class="max-w-md space-y-3 rounded-lg bg-white p-5 shadow">
            <h2 class="font-medium">Alerts and spending limits</h2>
            <form method="POST" action="{{ route('credits.settings') }}" class="space-y-3">
                @csrf @method('PUT')
                <div><label for="low_balance_threshold" class="block text-sm font-medium text-gray-700">Low-credit alert (email when it falls below this)</label>
                    <input id="low_balance_threshold" name="low_balance_threshold" inputmode="decimal" value="{{ old('low_balance_threshold', $wallet?->low_balance_threshold !== null ? \App\Credits\CreditMath::plain($wallet->low_balance_threshold) : '') }}" class="w-full rounded-md border border-gray-300 px-3 py-2" placeholder="for example 50"></div>
                <div><label for="spending_limit_monthly" class="block text-sm font-medium text-gray-700">Monthly spending limit (empty = unlimited)</label>
                    <input id="spending_limit_monthly" name="spending_limit_monthly" inputmode="decimal" value="{{ old('spending_limit_monthly', $wallet?->spending_limit_monthly !== null ? \App\Credits\CreditMath::plain($wallet->spending_limit_monthly) : '') }}" class="w-full rounded-md border border-gray-300 px-3 py-2" placeholder="for example 1000"></div>
                <x-button>Save</x-button>
            </form>
        </section>
    @endif
</x-layouts.app>
