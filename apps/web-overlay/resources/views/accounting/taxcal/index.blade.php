<x-layouts.app title="Tax calendar">
    {{-- JARVIS ONE: page-specific visual identity; existing actions preserved --}}
    <header class="jarvis-module-hero jarvis-accent-violet" data-jarvis-module="accounting" aria-label="Tax calendar">
      <div class="jarvis-module-hero__identity"><span class="jarvis-module-hero__glyph" aria-hidden="true">✦</span><div>
        <div class="jarvis-module-hero__eyebrow">JARVIS ONE <span aria-hidden="true">/</span> ACCOUNTING</div>
        <h1>Tax calendar</h1><p>Review transactions, balances and accounting workflows.</p>
      </div></div>
      <span class="jarvis-module-hero__section">Accounting Workspace</span>
    </header>

    @php($kinds = ['vat_return' => 'VAT return', 'vds_deposit' => 'VDS Deposit', 'tds_deposit' => 'TDS Deposit', 'income_tax' => 'Income tax return', 'advance_tax' => 'Advance tax', 'license' => 'License/renewal', 'other' => 'Other'])
    @php($freqs = ['monthly' => 'Monthly', 'quarterly' => 'Quarterly', 'yearly' => 'Yearly', 'once' => 'Once'])
    @php($badge = ['overdue' => 'bg-red-100 text-red-800', 'due_soon' => 'bg-amber-100 text-amber-800', 'upcoming' => 'bg-gray-100 text-gray-700', 'done' => 'bg-green-100 text-green-800'])
    @php($label = ['overdue' => 'Overdue', 'due_soon' => 'Soon', 'upcoming' => 'Upcoming', 'done' => 'Done'])
    <h2 class="text-xl font-semibold">Tax calendar</h2>
    @include('accounting._nav')
    <p class="rounded bg-amber-50 p-3 text-sm text-amber-800">No government dates are built in here. Tax deposit/return dates change from time to time and differ by source — so you or your accountant set each task’s date yourself. This page only reminds you; it does not deposit or file anything. <b>Be sure to verify the dates in the starter draft list.</b></p>

    @if ($errors->any())<p class="rounded bg-red-50 p-3 text-sm text-red-700">{{ $errors->first() }}</p>@endif
    @if (session('status'))<p class="rounded bg-green-50 p-3 text-sm text-green-700">{{ session('status') }}</p>@endif

    <section class="grid gap-3 text-sm sm:grid-cols-4">
        @foreach (['overdue', 'due_soon', 'upcoming', 'done'] as $s)<div class="rounded-lg bg-white p-4 shadow"><p class="text-gray-500">{{ $label[$s] }}</p><p class="text-2xl font-semibold {{ $s === 'overdue' && $counts[$s] > 0 ? 'text-red-700' : '' }}">{{ $counts[$s] }}</p></div>@endforeach
    </section>

    <section class="overflow-x-auto rounded-lg bg-white shadow"><p class="px-4 pt-3 font-semibold">Task list (last 90 days to next 120 days)</p>
        <table class="min-w-full text-sm"><thead class="bg-gray-50 text-left text-xs text-gray-500"><tr><th class="px-3 py-2">Date</th><th class="px-3 py-2">Job</th><th class="px-3 py-2">Status</th><th class="px-3 py-2">Job</th></tr></thead><tbody>
        @forelse ($agenda as $a)<tr class="border-t"><td class="px-3 py-2">{{ $a['due'] }}<br><span class="text-xs text-gray-500">{{ $a['days'] === 0 ? 'Today' : ($a['days'] > 0 ? $a['days'].' days left' : (-$a['days']).' days ago') }}</span></td>
            <td class="px-3 py-2">{{ $a['label'] }}<br><span class="text-xs text-gray-500">{{ $kinds[$a['kind']] ?? $a['kind'] }}</span></td>
            <td class="px-3 py-2"><span class="rounded px-2 py-0.5 text-xs {{ $badge[$a['status']] }}">{{ $label[$a['status']] }}</span>@if ($a['done_on']) <span class="text-xs text-gray-500">({{ $a['done_on'] }})</span>@endif</td>
            <td class="px-3 py-2">@if ($canManage)
                @if ($a['status'] === 'done')<form method="POST" action="{{ route('accounting.cal.undo', $a['id']) }}">@csrf<input type="hidden" name="due" value="{{ $a['due'] }}"><button class="rounded bg-gray-100 px-2 py-0.5 text-xs">Tick</button></form>
                @else<form method="POST" action="{{ route('accounting.cal.done', $a['id']) }}" class="flex flex-wrap gap-1">@csrf<input type="hidden" name="due" value="{{ $a['due'] }}"><input type="date" name="on" value="{{ $today }}" max="{{ $today }}" required class="rounded border px-1 py-0.5 text-xs"><input name="note" maxlength="200" placeholder="Note/challan no." class="w-32 rounded border px-1 py-0.5 text-xs"><button class="rounded bg-indigo-600 px-2 py-0.5 text-xs text-white">Done</button></form>@endif
            @endif</td></tr>
        @empty<tr><td colspan="4" class="px-3 py-6 text-center text-gray-500">No tasks. Add below or take the starter draft list.</td></tr>@endforelse</tbody></table></section>

    @if ($canManage)
    <section class="rounded-lg bg-white p-5 text-sm shadow"><p class="font-semibold">Add a new task</p>
        <form method="POST" action="{{ route('accounting.cal.save') }}" class="mt-2 grid gap-2 sm:grid-cols-4">@csrf
            <label class="sm:col-span-2">Name<input name="label" maxlength="80" required value="{{ old('label') }}" class="mt-1 w-full rounded border px-2 py-1"></label>
            <label>Type<select name="kind" class="mt-1 w-full rounded border px-2 py-1">@foreach ($kinds as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach</select></label>
            <label>Repeat every how many days<select name="freq" class="mt-1 w-full rounded border px-2 py-1">@foreach ($freqs as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach</select></label>
            <label>Day of month (1–31)<input name="day" inputmode="numeric" maxlength="2" class="mt-1 w-full rounded border px-2 py-1"></label>
            <label>Month (yearly: 1–12; quarterly: first month of cycle 1–3)<input name="month" inputmode="numeric" maxlength="2" class="mt-1 w-full rounded border px-2 py-1"></label>
            <label>Date (only "Once")<input type="date" name="once" class="mt-1 w-full rounded border px-2 py-1"></label>
            <label>Remind how many days before<input name="remind" inputmode="numeric" maxlength="2" value="7" required class="mt-1 w-full rounded border px-2 py-1"></label>
            <div class="sm:col-span-4"><button class="rounded bg-indigo-600 px-3 py-1 text-white">Add</button> <span class="text-xs text-gray-500">If you give 31, the last day is used in shorter months.</span></div>
        </form></section>
    @endif

    <section class="overflow-x-auto rounded-lg bg-white shadow"><p class="px-4 pt-3 font-semibold">My tasks</p>
        <table class="min-w-full text-sm"><tbody>@forelse ($obligations as $o)<tr class="border-t {{ $o['active'] ? '' : 'text-gray-400' }}"><td class="px-3 py-2">{{ $o['label'] }}</td><td class="px-3 py-2">{{ $kinds[$o['kind']] ?? $o['kind'] }}</td>
            <td class="px-3 py-2">{{ $freqs[$o['freq']] }} @if ($o['freq'] === 'once') {{ $o['once_date'] }} @else · {{ $o['due_day'] }} Date @if ($o['due_month']) · month(s) {{ $o['due_month'] }} @endif @endif</td><td class="px-3 py-2">{{ $o['remind_days'] }} days ago</td>
            <td class="px-3 py-2">@if ($canManage)<form method="POST" action="{{ route('accounting.cal.active', $o['id']) }}">@csrf<input type="hidden" name="active" value="{{ $o['active'] ? 0 : 1 }}"><button class="rounded bg-gray-100 px-2 py-0.5 text-xs">{{ $o['active'] ? 'Deactivate' : 'Activate' }}</button></form>@endif</td></tr>
        @empty<tr><td class="px-3 py-6 text-center text-gray-500">No tasks.@if ($canManage)<form method="POST" action="{{ route('accounting.cal.seed') }}" class="mt-2">@csrf<button class="rounded bg-indigo-600 px-3 py-1 text-white">Take the starter draft list</button></form>@endif</td></tr>@endforelse</tbody></table></section>
</x-layouts.app>
