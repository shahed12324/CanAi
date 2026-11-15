<x-layouts.app title="Salary structure">
    {{-- JARVIS ONE: page-specific visual identity; existing actions preserved --}}
    <header class="jarvis-module-hero jarvis-accent-violet" data-jarvis-module="accounting" aria-label="Salary structure">
      <div class="jarvis-module-hero__identity"><span class="jarvis-module-hero__glyph" aria-hidden="true">✦</span><div>
        <div class="jarvis-module-hero__eyebrow">JARVIS ONE <span aria-hidden="true">/</span> ACCOUNTING</div>
        <h1>Salary structure</h1><p>Review transactions, balances and accounting workflows.</p>
      </div></div>
      <span class="jarvis-module-hero__section">Accounting Workspace</span>
    </header>

    @php($m = fn ($v) => \App\Accounting\AccText::money((int) $v))
    <h2 class="text-xl font-semibold">{{ $e['name'] }} ({{ $e['code'] }}) — Salary structure</h2>
    @include('accounting._nav')
    <p class="text-sm"><a class="text-indigo-600" href="{{ route('accounting.payroll.employees') }}">← Employee</a></p>
    <p class="rounded bg-amber-50 p-3 text-sm text-amber-800">A new amount takes effect from the selected date and the previous one with the same label ends the day before — history is kept. A month’s run uses the structure active on that month’s <b> last day</b> .</p>
    @if ($errors->any())<p class="rounded bg-red-50 p-3 text-sm text-red-700">{{ $errors->first() }}</p>@endif
    @if (session('status'))<p class="rounded bg-green-50 p-3 text-sm text-green-700">{{ session('status') }}</p>@endif
    @if ($canManage)
    <form method="POST" action="{{ route('accounting.payroll.component', $e['id']) }}" class="grid gap-2 rounded-lg bg-white p-4 text-sm shadow sm:grid-cols-5">@csrf
        <label>Type<select name="kind" class="mt-1 w-full rounded border px-2 py-1"><option value="earning">Earnings (salary/allowances)</option><option value="deduction">Deductions</option></select></label>
        <label>Label<input name="label" maxlength="60" required placeholder="Basic salary / House rent / Income tax…" value="{{ old('label') }}" class="mt-1 w-full rounded border px-2 py-1"></label>
        <label>Monthly amount (৳)<input name="amount" required value="{{ old('amount') }}" class="mt-1 w-full rounded border px-2 py-1"></label>
        <label>From date<input type="date" name="from" required value="{{ old('from', $today) }}" class="mt-1 w-full rounded border px-2 py-1"></label>
        <label class="self-end"><input type="checkbox" name="tax" value="1"> This is income tax deducted from salary</label>
        <div class="sm:col-span-5"><button class="rounded bg-indigo-600 px-3 py-1 text-white">Add/Change</button></div></form>
    @endif
    <section class="overflow-x-auto rounded-lg bg-white shadow"><table class="min-w-full text-sm"><thead class="bg-gray-50 text-left text-xs text-gray-500"><tr><th class="px-3 py-2">Type</th><th class="px-3 py-2">Label</th><th class="px-3 py-2 text-right">Amount</th><th class="px-3 py-2"> from</th><th class="px-3 py-2">Until</th><th class="px-3 py-2"></th></tr></thead><tbody>
        @forelse ($components as $c)<tr class="border-t {{ $c['effective_to'] ? 'text-gray-400' : '' }}"><td class="px-3 py-2">{{ $c['kind'] === 'earning' ? 'Income' : ($c['is_tax'] ? 'Deductions (income tax)' : 'Deductions') }}</td><td class="px-3 py-2">{{ $c['label'] }}</td><td class="px-3 py-2 text-right">{{ $m($c['amount_minor']) }}</td><td class="px-3 py-2">{{ $c['effective_from'] }}</td><td class="px-3 py-2">{{ $c['effective_to'] ?? 'Ongoing' }}</td>
            <td class="px-3 py-2">@if ($canManage && ! $c['effective_to'])<form method="POST" action="{{ route('accounting.payroll.component.end', [$e['id'], $c['id']]) }}" class="flex gap-1">@csrf<input type="date" name="to" value="{{ $today }}" required class="rounded border px-1 py-0.5 text-xs"><button class="rounded bg-gray-100 px-2 py-0.5 text-xs">End</button></form>@endif</td></tr>
        @empty<tr><td colspan="6" class="px-3 py-6 text-center text-gray-500">No lines. First "Basic salary" Add.</td></tr>@endforelse</tbody></table></section>
</x-layouts.app>
