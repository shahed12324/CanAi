<x-layouts.app title="Account">
    {{-- JARVIS ONE: page-specific visual identity; existing actions preserved --}}
    <header class="jarvis-module-hero jarvis-accent-violet" data-jarvis-module="accounting" aria-label="Account">
      <div class="jarvis-module-hero__identity"><span class="jarvis-module-hero__glyph" aria-hidden="true">✦</span><div>
        <div class="jarvis-module-hero__eyebrow">JARVIS ONE <span aria-hidden="true">/</span> ACCOUNTING</div>
        <h1>Account</h1><p>Review transactions, balances and accounting workflows.</p>
      </div></div>
      <span class="jarvis-module-hero__section">Accounting Workspace</span>
    </header>

    <h2 class="text-xl font-semibold">Chart of accounts</h2>
    @include('accounting._nav')
    @if ($canSettings && ! $settings['seeded'])
        <form method="POST" action="{{ route('accounting.seed') }}" class="rounded bg-white p-5 text-sm shadow">@csrf<p class="mb-2">Add a standard chart of accounts for a small Bangladeshi business (assets, liabilities, equity, income, expenses)?</p><button class="rounded bg-indigo-600 px-3 py-1 text-white">Create chart</button></form>
    @endif
    <div class="overflow-x-auto rounded-lg bg-white shadow"><table class="min-w-full text-sm">
        <thead class="bg-gray-50 text-left text-xs text-gray-500"><tr><th class="px-3 py-2">Code</th><th class="px-3 py-2">Name</th><th class="px-3 py-2">Type</th><th class="px-3 py-2"></th></tr></thead>
        <tbody>@foreach ($accounts as $a)<tr class="border-t {{ $a['postable'] ? '' : 'bg-gray-50 font-semibold' }}"><td class="px-3 py-1">{{ $a['code'] }}</td><td class="px-3 py-1">{{ $a['name_bn'] ?: $a['name'] }} <span class="text-xs text-gray-400">{{ $a['name_bn'] ? $a['name'] : '' }}</span>@unless ($a['active']) <span class="text-xs text-red-600">(inactive)</span>@endunless</td><td class="px-3 py-1">{{ \App\Accounting\AccText::typeBn($a['type']) }}</td>
            <td class="px-3 py-1">@if ($canSettings && $a['postable'])<form method="POST" action="{{ route('accounting.accounts.active', $a['id']) }}">@csrf<input type="hidden" name="active" value="{{ $a['active'] ? 0 : 1 }}"><button class="text-xs text-indigo-600">{{ $a['active'] ? 'Deactivate' : 'Activate' }}</button></form>@endif</td></tr>@endforeach</tbody></table></div>
    @if ($canSettings && $settings['seeded'])
        <form method="POST" action="{{ route('accounting.accounts.store') }}" class="grid gap-2 rounded-lg bg-white p-5 text-sm shadow sm:grid-cols-5">@csrf
            <input name="code" placeholder="Code (6960)" required class="rounded border px-2 py-1"><input name="name" placeholder="Name" required class="rounded border px-2 py-1"><input name="name_bn" placeholder="Bengali name" class="rounded border px-2 py-1">
            <select name="type" class="rounded border px-2 py-1">@foreach (['asset', 'liability', 'equity', 'income', 'expense'] as $t)<option value="{{ $t }}">{{ \App\Accounting\AccText::typeBn($t) }}</option>@endforeach</select>
            <select name="parent_id" class="rounded border px-2 py-1"><option value="">No parent</option>@foreach ($accounts as $a)@unless ($a['postable'])<option value="{{ $a['id'] }}">{{ $a['code'] }} {{ $a['name'] }}</option>@endunless @endforeach</select>
            <div class="sm:col-span-5"><button class="rounded bg-indigo-600 px-3 py-1 text-white">Add account</button></div></form>
    @endif
</x-layouts.app>
