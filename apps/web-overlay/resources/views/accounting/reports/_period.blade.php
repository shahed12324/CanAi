<form method="GET" class="flex flex-wrap items-end gap-2 text-sm print:hidden">
    <select name="preset" class="rounded border px-2 py-1">@foreach (['month', 'last_month', 'fiscal_year', 'last_fiscal_year', 'calendar_year', 'custom'] as $p)<option value="{{ $p }}" @selected($preset === $p)>{{ \App\Accounting\AccReport::presetBn($p) }}</option>@endforeach</select>
    <label> from <input type="date" name="from" value="{{ $from }}" class="rounded border px-1 py-1"></label><label>Until <input type="date" name="to" value="{{ $to }}" class="rounded border px-1 py-1"></label>
    <button class="rounded bg-indigo-600 px-3 py-1 text-white">View</button><button type="button" onclick="window.print()" class="rounded border px-3 py-1">Print</button>
    <span class="text-xs text-gray-500">(To enter your own date, choose from the list "Custom date" Select)</span></form>
