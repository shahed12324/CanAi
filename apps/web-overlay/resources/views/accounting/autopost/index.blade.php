<x-layouts.app title="Automatic posting">
    {{-- JARVIS ONE: page-specific visual identity; existing actions preserved --}}
    <header class="jarvis-module-hero jarvis-accent-violet" data-jarvis-module="accounting" aria-label="Automatic posting">
      <div class="jarvis-module-hero__identity"><span class="jarvis-module-hero__glyph" aria-hidden="true">✦</span><div>
        <div class="jarvis-module-hero__eyebrow">JARVIS ONE <span aria-hidden="true">/</span> ACCOUNTING</div>
        <h1>Automatic posting</h1><p>Review transactions, balances and accounting workflows.</p>
      </div></div>
      <span class="jarvis-module-hero__section">Accounting Workspace</span>
    </header>

    @php($m = fn ($v) => \App\Accounting\AccText::money((int) $v))
    <h2 class="text-xl font-semibold">Automatic bookkeeping from orders and courier receivables</h2>
    @include('accounting._nav')
    <section class="rounded-lg bg-white p-5 text-sm shadow">
        <p>Order <b>Delivery</b> , the sale (and cost of goods sold, if the cost is known) is posted to the journal automatically; after delivery <b> return</b> the entry is reversed. When sent by courier, the money is "receivable from the courier"-; for your own delivery, "cash"-. If there is any problem with the books, the order work does not stop — it stays in the list below, "Pending posts" .</p>
        <p class="mt-2">Status: <b class="{{ $enabled ? 'text-green-700' : 'text-gray-600' }}">{{ $enabled ? 'Active' : 'Closed' }}</b> · Pending {{ $counts['pending'] }} · Failed {{ $counts['failed'] }} · Posted {{ $counts['posted'] }}</p>
        @if ($canManage)
            <div class="mt-3 flex flex-wrap gap-2">
                <form method="POST" action="{{ route('accounting.autopost.toggle') }}">@csrf<input type="hidden" name="on" value="{{ $enabled ? 0 : 1 }}"><button class="rounded {{ $enabled ? 'bg-gray-200' : 'bg-indigo-600 text-white' }} px-3 py-1">{{ $enabled ? 'Deactivate' : 'Activate' }}</button></form>
                <form method="POST" action="{{ route('accounting.autopost.run') }}">@csrf<button class="rounded bg-indigo-600 px-3 py-1 text-white">Post now / Retry</button></form>
            </div>
            @unless ($enabled)<p class="mt-2 text-xs text-gray-500">Orders delivered before this was turned on are not posted automatically. It also runs on its own on schedule (every 10 minutes, if the scheduler is running).</p>@endunless
        @endif
    </section>

    @if ($counts['failed'] > 0 || $counts['pending'] > 0)
    <section class="overflow-x-auto rounded-lg bg-white shadow"><p class="px-4 pt-3 font-semibold">Pending posts</p><table class="min-w-full text-sm"><thead class="bg-gray-50 text-left text-xs text-gray-500"><tr><th class="px-3 py-2">Order</th><th class="px-3 py-2">Job</th><th class="px-3 py-2">Status / reason</th><th class="px-3 py-2"></th></tr></thead><tbody>
        @foreach ($queue as $q)@if (in_array($q['status'], ['pending', 'failed'], true))
            <tr class="border-t"><td class="px-3 py-2">#{{ $q['order_number'] }} {{ $q['customer_name'] }}<br><span class="text-xs text-gray-500">{{ $m($q['order_total_minor']) }} ৳</span></td><td class="px-3 py-2">{{ $q['kind'] === 'sale' ? 'Sale' : 'return' }}</td>
                <td class="px-3 py-2">@if ($q['status'] === 'failed')<span class="text-red-700">Failed ({{ $q['attempts'] }} times)</span><br><span class="text-xs">{{ \App\Accounting\AccText::autopostFailure($q['last_error']) }}</span>@else Pending @endif</td>
                <td class="px-3 py-2">@if ($canManage)<form method="POST" action="{{ route('accounting.autopost.skip', $q['seq']) }}" class="flex gap-1">@csrf<input name="reason" maxlength="150" placeholder="Reason (optional)" class="w-32 rounded border px-2 py-1 text-xs"><button class="rounded bg-gray-100 px-2 py-1 text-xs">Skip</button></form>@endif</td></tr>
        @endif @endforeach</tbody></table></section>
    @endif

    <section class="overflow-x-auto rounded-lg bg-white shadow"><p class="px-4 pt-3 font-semibold"> receivable from the courier</p><table class="min-w-full text-sm"><thead class="bg-gray-50 text-left text-xs text-gray-500"><tr><th class="px-3 py-2">Courier</th><th class="px-3 py-2 text-right">Delivery</th><th class="px-3 py-2 text-right"> return</th><th class="px-3 py-2 text-right">Received</th><th class="px-3 py-2 text-right">Outstanding</th></tr></thead><tbody>
        @forelse ($receivable as $r)<tr class="border-t"><td class="px-3 py-2">{{ \App\Accounting\AccText::courierBn($r['courier']) }} <span class="text-xs text-gray-500">({{ $r['orders'] }})</span></td><td class="px-3 py-2 text-right">{{ $m($r['delivered_minor']) }}</td><td class="px-3 py-2 text-right">{{ $m($r['returned_minor']) }}</td><td class="px-3 py-2 text-right">{{ $m($r['settled_minor']) }}</td><td class="px-3 py-2 text-right font-semibold">{{ $m($r['outstanding_minor']) }}</td></tr>
        @empty<tr><td colspan="5" class="px-3 py-6 text-center text-gray-500">There are no deliveries posted through the courier yet.</td></tr>@endforelse</tbody></table></section>

    @if ($canManage && $receivable)
    <form method="POST" action="{{ route('accounting.autopost.settle') }}" class="grid gap-2 rounded-lg bg-white p-5 text-sm shadow sm:grid-cols-3">@csrf
        <p class="font-semibold sm:col-span-3">The courier has sent the money</p>
        <label>Courier<select name="courier" class="mt-1 w-full rounded border px-2 py-1">@foreach ($receivable as $r)<option value="{{ $r['courier'] }}">{{ \App\Accounting\AccText::courierBn($r['courier']) }} (Outstanding {{ $m($r['outstanding_minor']) }})</option>@endforeach</select></label>
        <label>Date<input type="date" name="date" max="{{ $today }}" required value="{{ old('date', $today) }}" class="mt-1 w-full rounded border px-2 py-1"></label>
        <label>Account the money came into<select name="account_id" class="mt-1 w-full rounded border px-2 py-1">@foreach ($cash as $a)<option value="{{ $a['id'] }}">{{ $a['code'] }} {{ $a['name_bn'] ?: $a['name'] }}</option>@endforeach</select></label>
        <label>Total COD collected by the courier (৳)<input name="gross" required inputmode="decimal" value="{{ old('gross') }}" class="mt-1 w-full rounded border px-2 py-1"></label>
        <label>Amount withheld by the courier (৳)<input name="fee" inputmode="decimal" value="{{ old('fee') }}" class="mt-1 w-full rounded border px-2 py-1"></label>
        <label>Note<input name="note" maxlength="200" value="{{ old('note') }}" class="mt-1 w-full rounded border px-2 py-1"></label>
        <p class="text-xs text-gray-500 sm:col-span-3">Will be credited to the account (total − withheld), withheld part "Delivery and courier costs"-will go to, and the courier receivable will reduce by the total.</p>
        <button class="rounded bg-indigo-600 px-3 py-1 text-white sm:col-span-3">Record</button></form>
    @endif

    <section class="overflow-x-auto rounded-lg bg-white shadow"><p class="px-4 pt-3 font-semibold">History of money received from the courier</p><table class="min-w-full text-sm"><tbody>
        @forelse ($settlements as $s)<tr class="border-t {{ $s['voided_at'] ? 'text-gray-400 line-through' : '' }}"><td class="px-3 py-2">{{ $s['settle_date'] }}</td><td class="px-3 py-2">{{ \App\Accounting\AccText::courierBn($s['courier']) }}</td><td class="px-3 py-2 text-right">{{ $m($s['gross_minor']) }} (Withheld {{ $m($s['fee_minor']) }})</td><td class="px-3 py-2">{{ $s['account_name'] }}</td><td class="px-3 py-2">Entry #{{ $s['entry_number'] }}</td>
            <td class="px-3 py-2">@if ($canManage && ! $s['voided_at'])<form method="POST" action="{{ route('accounting.autopost.void', $s['id']) }}">@csrf<button class="rounded bg-gray-100 px-2 py-1 text-xs">Cancel</button></form>@endif</td></tr>
        @empty<tr><td class="px-3 py-6 text-center text-gray-500">Nothing yet.</td></tr>@endforelse</tbody></table></section>

    <section class="overflow-x-auto rounded-lg bg-white shadow"><p class="px-4 pt-3 font-semibold">Recent automatic postings</p><table class="min-w-full text-sm"><tbody>
        @foreach ($queue as $q)@if (in_array($q['status'], ['posted', 'skipped'], true))
            <tr class="border-t"><td class="px-3 py-2">#{{ $q['order_number'] }}</td><td class="px-3 py-2">{{ $q['kind'] === 'sale' ? 'Sale' : 'return' }}</td><td class="px-3 py-2">{{ $q['status'] === 'posted' ? 'Entry #'.$q['entry_number'] : 'Dismissed' }}</td><td class="px-3 py-2 text-xs text-gray-500">{{ $q['note'] }}</td></tr>
        @endif @endforeach</tbody></table></section>
</x-layouts.app>
