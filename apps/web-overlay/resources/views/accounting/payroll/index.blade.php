<x-layouts.app title="Payroll">
    {{-- JARVIS ONE: page-specific visual identity; existing actions preserved --}}
    <header class="jarvis-module-hero jarvis-accent-violet" data-jarvis-module="accounting" aria-label="Payroll">
      <div class="jarvis-module-hero__identity"><span class="jarvis-module-hero__glyph" aria-hidden="true">✦</span><div>
        <div class="jarvis-module-hero__eyebrow">JARVIS ONE <span aria-hidden="true">/</span> ACCOUNTING</div>
        <h1>Payroll</h1><p>Review transactions, balances and accounting workflows.</p>
      </div></div>
      <span class="jarvis-module-hero__section">Accounting Workspace</span>
    </header>

    @php($m = fn ($v) => \App\Accounting\AccText::money((int) $v))
    <h2 class="text-xl font-semibold">Payroll</h2>
    @include('accounting._nav')
    <p class="rounded bg-amber-50 p-3 text-sm text-amber-800">There are no built-in income tax slabs, provident fund rules or minimum wage here — you or your accountant enter every amount in the employee structure. If someone works part of a month (joining/leaving/unpaid leave), set the amount through a one-time deduction/bonus. <b>Check the amounts and tax deductions with your accountant.</b></p>
    @if ($errors->any())<p class="rounded bg-red-50 p-3 text-sm text-red-700">{{ $errors->first() }}</p>@endif
    @if (session('status'))<p class="rounded bg-green-50 p-3 text-sm text-green-700">{{ session('status') }}</p>@endif
    <p class="text-sm"><a class="text-indigo-600" href="{{ route('accounting.payroll.employees') }}">Employees and salary structure →</a></p>
    @if ($canManage)
    <form method="POST" action="{{ route('accounting.payroll.create') }}" class="flex flex-wrap items-end gap-2 rounded-lg bg-white p-4 text-sm shadow">@csrf
        <label> month(s)<input type="month" name="period" value="{{ old('period', $period) }}" max="{{ $period }}" required class="mt-1 block rounded border px-2 py-1"></label>
        <button class="rounded bg-indigo-600 px-3 py-1 text-white">Create this month’s draft run</button></form>
    @endif
    <section class="overflow-x-auto rounded-lg bg-white shadow"><table class="min-w-full text-sm"><thead class="bg-gray-50 text-left text-xs text-gray-500"><tr><th class="px-3 py-2"> month(s)</th><th class="px-3 py-2">Status</th><th class="px-3 py-2 text-right">Employee</th><th class="px-3 py-2 text-right">Total earnings</th><th class="px-3 py-2 text-right">Deductions</th><th class="px-3 py-2 text-right">Net</th></tr></thead><tbody>
        @forelse ($runs as $r)<tr class="border-t {{ $r['status'] === 'void' ? 'text-gray-400 line-through' : '' }}"><td class="px-3 py-2"><a class="text-indigo-600" href="{{ route('accounting.payroll.run', $r['id']) }}">{{ \App\Payroll\PayrollText::month($r['period']) }}</a></td>
            <td class="px-3 py-2">{{ \App\Payroll\PayrollText::STATUS[$r['status']] }}</td><td class="px-3 py-2 text-right">{{ $r['staff'] }}</td><td class="px-3 py-2 text-right">{{ $m($r['gross_minor']) }}</td><td class="px-3 py-2 text-right">{{ $m($r['deduction_minor']) }}</td><td class="px-3 py-2 text-right font-semibold">{{ $m($r['net_minor']) }}</td></tr>
        @empty<tr><td colspan="6" class="px-3 py-6 text-center text-gray-500">No runs. First add employees and a structure, then create the month’s run.</td></tr>@endforelse</tbody></table></section>
</x-layouts.app>
