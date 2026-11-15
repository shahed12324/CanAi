<x-layouts.app title="Journal">
    {{-- JARVIS ONE: page-specific visual identity; existing actions preserved --}}
    <header class="jarvis-module-hero jarvis-accent-violet" data-jarvis-module="accounting" aria-label="Journal">
      <div class="jarvis-module-hero__identity"><span class="jarvis-module-hero__glyph" aria-hidden="true">✦</span><div>
        <div class="jarvis-module-hero__eyebrow">JARVIS ONE <span aria-hidden="true">/</span> ACCOUNTING</div>
        <h1>Journal</h1><p>Review transactions, balances and accounting workflows.</p>
      </div></div>
      <span class="jarvis-module-hero__section">Accounting Workspace</span>
    </header>

    <div class="flex items-center justify-between"><h2 class="text-xl font-semibold">Journal</h2>@if ($canManage)<a class="rounded bg-indigo-600 px-3 py-1 text-sm text-white" href="{{ route('accounting.create') }}">New entry</a>@endif</div>
    @include('accounting._nav')
    @if ($settings['locked_through'])<p class="text-sm text-amber-700">🔒 {{ $settings['locked_through'] }} Books are closed until.</p>@endif
    @if (! $settings['seeded'])<p class="rounded bg-amber-50 p-3 text-sm text-amber-800">First <a class="underline" href="{{ route('accounting.accounts') }}">Chart of accounts</a> create.</p>@endif
    <div class="overflow-x-auto rounded-lg bg-white shadow"><table class="min-w-full text-sm">
        <thead class="bg-gray-50 text-left text-xs text-gray-500"><tr><th class="px-3 py-2">#</th><th class="px-3 py-2">Date</th><th class="px-3 py-2">Description</th><th class="px-3 py-2 text-right">Amount</th></tr></thead>
        <tbody>@forelse ($rows as $r)<tr class="border-t"><td class="px-3 py-2"><a class="text-indigo-600" href="{{ route('accounting.show', $r['id']) }}">{{ $r['number'] }}</a></td><td class="px-3 py-2">{{ $r['entry_date'] }}</td><td class="px-3 py-2">{{ $r['memo'] }}@if ($r['source'] === 'reversal') <span class="rounded bg-gray-100 px-1 text-xs">Reversal entry</span>@endif</td><td class="px-3 py-2 text-right">{{ \App\Accounting\AccText::money((int) $r['total']) }}</td></tr>
        @empty<tr><td colspan="4" class="px-3 py-6 text-center text-gray-500">No entries.</td></tr>@endforelse</tbody></table></div>
</x-layouts.app>
