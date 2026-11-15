<x-layouts.app title="Employee">
    {{-- JARVIS ONE: page-specific visual identity; existing actions preserved --}}
    <header class="jarvis-module-hero jarvis-accent-violet" data-jarvis-module="accounting" aria-label="Employee">
      <div class="jarvis-module-hero__identity"><span class="jarvis-module-hero__glyph" aria-hidden="true">✦</span><div>
        <div class="jarvis-module-hero__eyebrow">JARVIS ONE <span aria-hidden="true">/</span> ACCOUNTING</div>
        <h1>Employee</h1><p>Review transactions, balances and accounting workflows.</p>
      </div></div>
      <span class="jarvis-module-hero__section">Accounting Workspace</span>
    </header>

    @php($m = fn ($v) => \App\Accounting\AccText::money((int) $v))
    <h2 class="text-xl font-semibold">Employees and salary structure</h2>
    @include('accounting._nav')
    <p class="text-sm"><a class="text-indigo-600" href="{{ route('accounting.payroll') }}">← Payroll run</a></p>
    @if ($errors->any())<p class="rounded bg-red-50 p-3 text-sm text-red-700">{{ $errors->first() }}</p>@endif
    @if (session('status'))<p class="rounded bg-green-50 p-3 text-sm text-green-700">{{ session('status') }}</p>@endif
    @if ($canManage)
    <form method="POST" action="{{ route('accounting.payroll.employee') }}" class="grid gap-2 rounded-lg bg-white p-4 text-sm shadow sm:grid-cols-5">@csrf
        <label>Code<input name="code" maxlength="20" required value="{{ old('code') }}" class="mt-1 w-full rounded border px-2 py-1"></label>
        <label>Name<input name="name" maxlength="80" required value="{{ old('name') }}" class="mt-1 w-full rounded border px-2 py-1"></label>
        <label>Designation<input name="designation" maxlength="80" value="{{ old('designation') }}" class="mt-1 w-full rounded border px-2 py-1"></label>
        <label>Joined<input type="date" name="join" required value="{{ old('join') }}" class="mt-1 w-full rounded border px-2 py-1"></label>
        <label>Left (optional)<input type="date" name="end" value="{{ old('end') }}" class="mt-1 w-full rounded border px-2 py-1"></label>
        <div class="sm:col-span-5"><button class="rounded bg-indigo-600 px-3 py-1 text-white">Add employee</button></div></form>
    @endif
    <section class="overflow-x-auto rounded-lg bg-white shadow"><table class="min-w-full text-sm"><thead class="bg-gray-50 text-left text-xs text-gray-500"><tr><th class="px-3 py-2">Code</th><th class="px-3 py-2">Name</th><th class="px-3 py-2">Designation</th><th class="px-3 py-2">Joined</th><th class="px-3 py-2 text-right">Current monthly earnings</th><th class="px-3 py-2"></th></tr></thead><tbody>
        @forelse ($employees as $e)<tr class="border-t {{ $e['active'] ? '' : 'text-gray-400' }}"><td class="px-3 py-2">{{ $e['code'] }}</td><td class="px-3 py-2"><a class="text-indigo-600" href="{{ route('accounting.payroll.structure', $e['id']) }}">{{ $e['name'] }}</a></td><td class="px-3 py-2">{{ $e['designation'] }}</td>
            <td class="px-3 py-2">{{ $e['join_date'] }}@if ($e['end_date']) — {{ $e['end_date'] }}@endif</td><td class="px-3 py-2 text-right">{{ $m($e['gross_minor']) }}</td>
            <td class="px-3 py-2">@if ($canManage)<a class="mr-2 text-xs text-indigo-600" href="{{ route('accounting.payroll.pay.details', $e['id']) }}">Payment details @if (! isset($pay[$e['id']])) <span class="text-red-600">(none)</span>@endif</a><form method="POST" action="{{ route('accounting.payroll.employee.active', $e['id']) }}" class="inline">@csrf<input type="hidden" name="active" value="{{ $e['active'] ? 0 : 1 }}"><button class="rounded bg-gray-100 px-2 py-0.5 text-xs">{{ $e['active'] ? 'Closed' : 'Active' }}</button></form>@endif</td></tr>
        @empty<tr><td colspan="6" class="px-3 py-6 text-center text-gray-500">No employees.</td></tr>@endforelse</tbody></table></section>
</x-layouts.app>
