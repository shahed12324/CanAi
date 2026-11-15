<x-layouts.app title="Payroll run">
    {{-- JARVIS ONE: page-specific visual identity; existing actions preserved --}}
    <header class="jarvis-module-hero jarvis-accent-violet" data-jarvis-module="accounting" aria-label="Payroll run">
      <div class="jarvis-module-hero__identity"><span class="jarvis-module-hero__glyph" aria-hidden="true">✦</span><div>
        <div class="jarvis-module-hero__eyebrow">JARVIS ONE <span aria-hidden="true">/</span> ACCOUNTING</div>
        <h1>Payroll run</h1><p>Review transactions, balances and accounting workflows.</p>
      </div></div>
      <span class="jarvis-module-hero__section">Accounting Workspace</span>
    </header>

    @php($m = fn ($v) => \App\Accounting\AccText::money((int) $v))
    @php($draft = $run['status'] === 'draft')
    <h2 class="text-xl font-semibold">{{ \App\Payroll\PayrollText::month($run['period']) }} — Payroll run</h2>
    @include('accounting._nav')
    <p class="text-sm"><a class="text-indigo-600" href="{{ route('accounting.payroll') }}">← All runs</a></p>
    @if ($errors->any())<p class="rounded bg-red-50 p-3 text-sm text-red-700">{{ $errors->first() }}</p>@endif
    @if (session('status'))<p class="rounded bg-green-50 p-3 text-sm text-green-700">{{ session('status') }}</p>@endif
    <section class="grid gap-3 text-sm sm:grid-cols-5">
        <div class="rounded-lg bg-white p-4 shadow"><p class="text-gray-500">Status</p><p class="font-semibold">{{ \App\Payroll\PayrollText::STATUS[$run['status']] }}</p></div>
        <div class="rounded-lg bg-white p-4 shadow"><p class="text-gray-500">Total earnings</p><p class="font-semibold">{{ $m($run['gross_minor']) }} ৳</p></div>
        <div class="rounded-lg bg-white p-4 shadow"><p class="text-gray-500">Total deductions</p><p class="font-semibold">{{ $m($run['deduction_minor']) }} ৳</p><p class="text-xs text-gray-500"> of which income tax {{ $m($run['tax_minor']) }} ৳</p></div>
        <div class="rounded-lg bg-white p-4 shadow"><p class="text-gray-500">Net payable</p><p class="font-semibold">{{ $m($run['net_minor']) }} ৳</p></div>
        <div class="rounded-lg bg-white p-4 shadow"><p class="text-gray-500">Employee</p><p class="font-semibold">{{ count($lines) }}</p></div>
    </section>

    @if (in_array($run['status'], ['posted', 'paid'], true))
    @php($plan = \App\Payroll\SalaryFile::plan($lines, $pay))
    <section class="rounded-lg bg-white p-4 text-sm shadow"><p class="font-semibold">Payslips and payroll file</p>
        <p class="mt-1"><a class="text-indigo-600" href="{{ route('accounting.payroll.slips', $run['id']) }}" target="_blank">Payslips for all employees (print / save as PDF)</a> — There is also a separate link next to each employee’s name.</p>
        @if ($canManage)
        <table class="mt-3 min-w-full"><thead class="text-left text-xs text-gray-500"><tr><th class="py-1">Channel</th><th class="py-1"> people</th><th class="py-1 text-right">Total</th><th class="py-1"></th></tr></thead><tbody>
            @foreach (\App\Payroll\SalaryFile::METHODS as $k => $label)<tr class="border-t"><td class="py-1">{{ $label }}</td><td class="py-1">{{ count($plan['rows'][$k]) }}</td><td class="py-1 text-right">{{ $m($plan['totals'][$k]) }} ৳</td>
                <td class="py-1 text-right">@if ($k !== 'cash' && $plan['rows'][$k] !== [])<a class="text-indigo-600" href="{{ route('accounting.payroll.file', [$run['id'], $k]) }}">CSV Download</a>@endif</td></tr>@endforeach</tbody></table>
        @if ($plan['missing'] !== [])<p class="mt-2 rounded bg-red-50 p-2 text-red-700">These employees have no payment details, so they are not in any file: @foreach ($plan['missing'] as $x)<a class="underline" href="{{ route('accounting.payroll.pay.details', $x['employee_id']) }}">{{ $x['name'] }}</a>@if (! $loop->last), @endif @endforeach</p>@endif
        <p class="mt-2 text-xs text-gray-500">Bank formats differ from bank to bank — this is a generic CSV; adapt it to your bank’s template. The file contains account/wallet numbers, so keep it safe and delete it when done. You send the money yourself through your bank/app.</p>
        @endif</section>
    @endif

    @foreach ($lines as $l)
    <section class="rounded-lg bg-white p-4 text-sm shadow"><div class="flex flex-wrap items-center justify-between gap-2"><p class="font-semibold">{{ $l['name'] }} <span class="text-gray-500">({{ $l['code'] }})</span>@if (in_array($run['status'], ['posted', 'paid'], true)) <a class="ml-2 text-xs font-normal text-indigo-600" href="{{ route('accounting.payroll.slips', $run['id']) }}?e={{ $l['employee_id'] }}" target="_blank">Payslip</a>@endif</p><p>Net: <b>{{ $m($l['net_minor']) }} ৳</b></p></div>
        <table class="mt-2 min-w-full"><tbody>@foreach ($l['items'] as $i)<tr class="border-t"><td class="py-1">{{ \App\Payroll\PayrollText::itemLabel($i) }}@if (! empty($i['tax'])) <span class="text-xs text-gray-500">(income tax)</span>@endif</td>
            <td class="py-1 text-right {{ $i['kind'] === 'deduction' ? 'text-red-700' : '' }}">{{ $i['kind'] === 'deduction' ? '−' : '' }}{{ $m($i['amount']) }}</td>
            <td class="py-1 text-right">@if ($canManage && $draft && ($i['src'] ?? 'structure') !== 'structure')<form method="POST" action="{{ route('accounting.payroll.adjust.remove', $run['id']) }}">@csrf<input type="hidden" name="employee_id" value="{{ $l['employee_id'] }}"><input type="hidden" name="item" value="{{ $i['id'] }}"><button class="rounded bg-gray-100 px-2 py-0.5 text-xs">Remove</button></form>@endif</td></tr>@endforeach</tbody></table>
        @if ($canManage && $draft)<form method="POST" action="{{ route('accounting.payroll.adjust', $run['id']) }}" class="mt-2 flex flex-wrap gap-1">@csrf<input type="hidden" name="employee_id" value="{{ $l['employee_id'] }}">
            <select name="kind" class="rounded border px-1 py-0.5 text-xs"><option value="bonus">Bonus</option><option value="deduction">One-time deduction</option></select><input name="label" maxlength="60" required placeholder="Description" class="w-40 rounded border px-1 py-0.5 text-xs"><input name="amount" required placeholder="Amount ৳" class="w-24 rounded border px-1 py-0.5 text-xs">
            <label class="text-xs"><input type="checkbox" name="tax" value="1"> Income tax</label><button class="rounded bg-indigo-600 px-2 py-0.5 text-xs text-white">Add</button></form>@endif</section>
    @endforeach

    @if ($canManage && $draft)
    <form method="POST" action="{{ route('accounting.payroll.post', $run['id']) }}" class="flex flex-wrap items-end gap-2 rounded-lg bg-white p-4 text-sm shadow">@csrf
        <label>Accounting date<input type="date" name="date" value="{{ $today }}" min="{{ $run['period'] }}-01" max="{{ $today }}" required class="mt-1 block rounded border px-2 py-1"></label>
        <button class="rounded bg-indigo-600 px-3 py-1 text-white">Post to the books (debit salary expense / credit payable)</button>
        <span class="text-xs text-gray-500">Once posted it cannot be changed; if wrong, cancel and create a new run.</span></form>
    @endif
    @if ($canManage && $run['status'] === 'posted')
    <form method="POST" action="{{ route('accounting.payroll.pay', $run['id']) }}" class="flex flex-wrap items-end gap-2 rounded-lg bg-white p-4 text-sm shadow">@csrf
        <label>Payment date<input type="date" name="date" value="{{ $today }}" max="{{ $today }}" required class="mt-1 block rounded border px-2 py-1"></label>
        <label>Account paid from<select name="account_id" class="mt-1 block rounded border px-2 py-1">@foreach ($cash as $a)<option value="{{ $a['id'] }}">{{ $a['code'] }} {{ $a['name_bn'] ?: $a['name'] }}</option>@endforeach</select></label>
        <button class="rounded bg-indigo-600 px-3 py-1 text-white">Net {{ $m($run['net_minor']) }} ৳ payment recorded</button>
        <span class="text-xs text-gray-500">You pay the money yourself through the bank/mobile banking — this is only the record.</span></form>
    @endif
    @if ($canManage && in_array($run['status'], ['draft', 'posted'], true))
    <form method="POST" action="{{ route('accounting.payroll.void', $run['id']) }}" class="flex flex-wrap items-end gap-2 text-sm" onsubmit="return confirm('Cancel this run?')">@csrf
        <label>Cancellation date<input type="date" name="date" value="{{ $today }}" max="{{ $today }}" required class="mt-1 block rounded border px-2 py-1"></label>
        <button class="rounded bg-red-100 px-3 py-1 text-red-800">Cancel run</button></form>
    @endif
</x-layouts.app>
