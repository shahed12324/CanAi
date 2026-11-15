<x-layouts.app title="Payment information">
    {{-- JARVIS ONE: page-specific visual identity; existing actions preserved --}}
    <header class="jarvis-module-hero jarvis-accent-violet" data-jarvis-module="accounting" aria-label="Payment information">
      <div class="jarvis-module-hero__identity"><span class="jarvis-module-hero__glyph" aria-hidden="true">✦</span><div>
        <div class="jarvis-module-hero__eyebrow">JARVIS ONE <span aria-hidden="true">/</span> ACCOUNTING</div>
        <h1>Payment information</h1><p>Review transactions, balances and accounting workflows.</p>
      </div></div>
      <span class="jarvis-module-hero__section">Accounting Workspace</span>
    </header>

    @php($methods = \App\Payroll\SalaryFile::METHODS)
    <h2 class="text-xl font-semibold">{{ $e['name'] }} ({{ $e['code'] }}) — How to pay salary</h2>
    @include('accounting._nav')
    <p class="text-sm"><a class="text-indigo-600" href="{{ route('accounting.payroll.employees') }}">← Employee</a></p>
    <p class="rounded bg-amber-50 p-3 text-sm text-amber-800">Bank/wallet numbers here are sensitive — they go only into the payroll file, not the payslip. For safety the saved number is not shown in full; to change it, enter it again and save (fill in all fields each time).</p>
    @if ($errors->any())<p class="rounded bg-red-50 p-3 text-sm text-red-700">{{ $errors->first() }}</p>@endif
    @if ($d)<p class="text-sm text-gray-600">Current: <b>{{ $methods[$d['method']] }}</b>@if ($d['method'] === 'bank') — {{ $d['bank_name'] }}@if ($d['branch']), {{ $d['branch'] }}@endif, Account {{ $masked['account'] }}@elseif ($d['method'] !== 'cash') — {{ $masked['wallet'] }}@endif</p>@endif
    <form method="POST" action="{{ route('accounting.payroll.pay.details.save', $e['id']) }}" class="grid gap-2 rounded-lg bg-white p-4 text-sm shadow sm:grid-cols-2">@csrf
        <label class="sm:col-span-2">Method<select name="method" class="mt-1 w-full rounded border px-2 py-1">@foreach ($methods as $k => $label)<option value="{{ $k }}" @selected(old('method', $d['method'] ?? 'bank') === $k)>{{ $label }}</option>@endforeach</select></label>
        <p class="text-xs text-gray-500 sm:col-span-2">If you choose bank, fill in the bank fields below; for bKash/Nagad, fill in the wallet number; "cash" needs nothing (text in other methods fields is ignored).</p>
        <div class="grid gap-2 sm:col-span-2 sm:grid-cols-2">
            <label>Bank name<input name="bank" maxlength="80" value="{{ old('bank') }}" class="mt-1 w-full rounded border px-2 py-1"></label>
            <label>Branch<input name="branch" maxlength="80" value="{{ old('branch') }}" class="mt-1 w-full rounded border px-2 py-1"></label>
            <label>Routing number (9 digits, optional)<input name="routing" maxlength="20" inputmode="numeric" value="{{ old('routing') }}" class="mt-1 w-full rounded border px-2 py-1"></label>
            <label>Account number<input name="account" maxlength="40" inputmode="numeric" autocomplete="off" value="{{ old('account') }}" class="mt-1 w-full rounded border px-2 py-1"></label></div>
        <div class="sm:col-span-2"><label>Mobile wallet number (017XXXXXXXX)<input name="wallet" maxlength="25" inputmode="tel" autocomplete="off" value="{{ old('wallet') }}" class="mt-1 w-full rounded border px-2 py-1"></label></div>
        <div class="sm:col-span-2"><button class="rounded bg-indigo-600 px-3 py-1 text-white">Save</button></div></form>
</x-layouts.app>
