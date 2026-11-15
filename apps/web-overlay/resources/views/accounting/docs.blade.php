<x-layouts.app title="{{ \App\Accounting\AccText::kindBn($kind) }}">
    {{-- JARVIS ONE: page-specific visual identity; existing actions preserved --}}
    <header class="jarvis-module-hero jarvis-accent-violet" data-jarvis-module="accounting" aria-label="Accounting — Docs">
      <div class="jarvis-module-hero__identity"><span class="jarvis-module-hero__glyph" aria-hidden="true">✦</span><div>
        <div class="jarvis-module-hero__eyebrow">JARVIS ONE <span aria-hidden="true">/</span> ACCOUNTING</div>
        <h1>Accounting — Docs</h1><p>Review transactions, balances and accounting workflows.</p>
      </div></div>
      <span class="jarvis-module-hero__section">Accounting Workspace</span>
    </header>

    <div class="flex items-center justify-between"><h2 class="text-xl font-semibold">{{ \App\Accounting\AccText::kindBn($kind) }}</h2>@if ($canManage)<a class="rounded bg-indigo-600 px-3 py-1 text-sm text-white" href="{{ route('accounting.docs.create', $plural) }}">New</a>@endif</div>
    @include('accounting._nav')
    <div class="overflow-x-auto rounded-lg bg-white shadow"><table class="min-w-full text-sm"><thead class="bg-gray-50 text-left text-xs text-gray-500"><tr><th class="px-3 py-2">Number</th><th class="px-3 py-2">Date</th><th class="px-3 py-2">{{ $kind === 'invoice' ? 'Customer' : 'Supplier' }}</th><th class="px-3 py-2 text-right">Total</th><th class="px-3 py-2 text-right">Outstanding</th><th class="px-3 py-2">Status</th></tr></thead><tbody>
        @forelse ($rows as $r)<tr class="border-t"><td class="px-3 py-2"><a class="text-indigo-600" href="{{ route('accounting.doc', $r['id']) }}">{{ $r['number'] }}</a></td><td class="px-3 py-2">{{ $r['issue_date'] }}</td><td class="px-3 py-2">{{ $r['party'] }}</td><td class="px-3 py-2 text-right">{{ \App\Accounting\AccText::money((int) $r['total_minor']) }}</td><td class="px-3 py-2 text-right">{{ $r['status'] === 'void' ? '' : \App\Accounting\AccText::money((int) $r['total_minor'] - (int) $r['paid_minor']) }}</td><td class="px-3 py-2">{{ \App\Accounting\AccText::statusBn($r['status']) }}</td></tr>
        @empty<tr><td colspan="6" class="px-3 py-6 text-center text-gray-500">Nothing.</td></tr>@endforelse</tbody></table></div>
</x-layouts.app>
